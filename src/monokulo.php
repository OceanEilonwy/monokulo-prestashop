<?php

/**
 * Monokulo for PrestaShop
 *
 * @author    Monokulo
 * @copyright Monokulo
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/lib/autoload.php';

use Monokulo\PrestaShop\ApiError;
use Monokulo\PrestaShop\Client;
use Monokulo\PrestaShop\Connection;
use Monokulo\PrestaShop\ConnectLink;
use Monokulo\PrestaShop\Logger;
use Monokulo\PrestaShop\OrderRequest;
use Monokulo\PrestaShop\Repository;
use Monokulo\PrestaShop\Shop\Orders;
use Monokulo\PrestaShop\Shop\PrestaShopDatabase;
use Monokulo\PrestaShop\Trace;
use Monokulo\PrestaShop\TransportError;
use Monokulo\PrestaShop\WebhookReceiver;
use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

/**
 * Monero at checkout through the merchant's own Monokulo.
 *
 * - Settings (getContent): the Monokulo address, the order statuses a
 *   payment moves an order through, and each shop's Connect button.
 * - Checkout: "Pay with Monero" places the order, creates its Monokulo order
 *   and sends the customer to pay it (controllers/front/validation.php).
 * - The payment link (controllers/front/pay.php) is in the order
 *   confirmation email and on the order in the customer's account.
 * - Monokulo's webhooks move the order on (controllers/front/webhook.php).
 * - The connect flow comes back to controllers/front/connect.php.
 */
class Monokulo extends PaymentModule
{
    /**
     * The order statuses the merchant chooses, with their defaults: the
     * module's own "Awaiting Monero payment" (null), or a PrestaShop status's
     * configuration key.
     */
    public const STATUSES = [
        'pending' => null,
        'waiting' => null,
        'partial' => null,
        'paid' => 'PS_OS_PAYMENT',
        'expired' => 'PS_OS_CANCELED',
    ];

    public const ADDRESS = 'MONOKULO_ADDRESS';
    public const FORWARD_ERRORS = 'MONOKULO_FORWARD_ERRORS';
    /** The id of the module's own "Awaiting Monero payment" status. */
    public const AWAITING_STATUS = 'MONOKULO_OS_AWAITING';

    public const LOG_FILE = 'monokulo.log';

    private const CONNECT_ERRORS = ['permission', 'address', 'host', 'finish', 'unavailable', 'unreachable'];

    private ?Trace $trace = null;
    private ?Logger $logger = null;

    public function __construct()
    {
        $this->name = 'monokulo';
        $this->tab = 'payments_gateways';
        $this->version = '0.1.0';
        $this->author = 'Monokulo';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '8.2.0', 'max' => '9.99.99'];
        $this->controllers = ['validation', 'pay', 'webhook', 'connect'];
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->trans('Monokulo (Monero)', [], 'Modules.Monokulo.Admin');
        $this->description = $this->trans('Accept Monero at checkout through your own Monokulo: a self-hosted, non-custodial payment processor that only ever holds a view key.', [], 'Modules.Monokulo.Admin');
    }

    public function isUsingNewTranslationSystem()
    {
        return true;
    }

    /**
     * Registers the hooks, creates the module's tables (kept from an earlier
     * install, with its connections) and its "Awaiting Monero payment"
     * status, and sets the default settings.
     */
    public function install()
    {
        if (!parent::install()
            || !$this->registerHook(['paymentOptions', 'displayPaymentReturn', 'displayOrderDetail'])) {
            return false;
        }
        $this->repository()->install();

        $awaiting = $this->installAwaitingStatus();
        Configuration::updateGlobalValue(self::ADDRESS, '');
        Configuration::updateGlobalValue(self::FORWARD_ERRORS, 0);
        foreach (self::STATUSES as $key => $default) {
            Configuration::updateGlobalValue($this->statusKey($key), $default === null ? $awaiting : (int) Configuration::get($default));
        }

        return true;
    }

    /**
     * Removes the settings. The tables and the "Awaiting Monero payment"
     * status stay: orders refer to them, and installing again picks the
     * connections back up.
     */
    public function uninstall()
    {
        foreach (array_keys(self::STATUSES) as $key) {
            Configuration::deleteByName($this->statusKey($key));
        }
        Configuration::deleteByName(self::ADDRESS);
        Configuration::deleteByName(self::FORWARD_ERRORS);
        Configuration::deleteByName(self::AWAITING_STATUS);

        return parent::uninstall();
    }

    /** The module's "Awaiting Monero payment" status, made once and found again after a reinstall. */
    private function installAwaitingStatus(): int
    {
        $id = (int) Db::getInstance()->getValue(
            'SELECT `id_order_state` FROM `' . _DB_PREFIX_ . "order_state` WHERE `module_name` = '" . pSQL($this->name) . "' AND `deleted` = 0 ORDER BY `id_order_state`"
        );
        if (!$id) {
            $state = new OrderState();
            foreach (Language::getLanguages(false) as $language) {
                $state->name[(int) $language['id_lang']] = $this->trans('Awaiting Monero payment', [], 'Modules.Monokulo.Admin', $language['locale']);
            }
            $state->module_name = $this->name;
            $state->color = '#F26822';
            $state->send_email = false;
            $state->logable = false;
            $state->paid = false;
            $state->invoice = false;
            $state->hidden = false;
            $state->unremovable = true;
            $state->add();
            $id = (int) $state->id;
        }
        Configuration::updateGlobalValue(self::AWAITING_STATUS, $id);

        return $id;
    }

    /* ------------------------------------------------------------------ */
    /* Settings */
    /* ------------------------------------------------------------------ */

    /** The module's settings page in the back office. */
    public function getContent()
    {
        $back = $this->adminUrl();

        if (Tools::getIsset('monokulo_connect')) {
            if (!$this->getPermission('configure')) {
                Tools::redirectAdmin($back . '&monokulo_connect_error=permission');
            }
            $start = $this->startConnect((int) Tools::getValue('monokulo_connect'), $back);
            Tools::redirect($start['redirect'] ?? $back . '&monokulo_connect_error=' . $start['error']);
        }

        $html = '';
        if (Tools::isSubmit('submitMonokulo')) {
            $errors = $this->getPermission('configure')
                ? $this->saveSettings($this->submittedSettings())
                : ['permission' => $this->text('error_permission')];
            foreach ($errors as $error) {
                $html .= $this->displayError($error);
            }
            if (!$errors) {
                $html .= $this->displayConfirmation($this->text('settings_saved'));
            }
        }

        if (Tools::getValue('monokulo_connected')) {
            $html .= $this->displayConfirmation($this->text('connected'));
        }
        $error = (string) Tools::getValue('monokulo_connect_error');
        if (in_array($error, self::CONNECT_ERRORS, true)) {
            $html .= $this->displayError($this->text('error_connect_' . $error));
        }

        return $html . $this->renderStores($back) . $this->renderSettings();
    }

    /** @return array<string, string> the posted form, as saveSettings takes it */
    private function submittedSettings(): array
    {
        $settings = [
            self::ADDRESS => (string) Tools::getValue(self::ADDRESS),
            self::FORWARD_ERRORS => (string) Tools::getValue(self::FORWARD_ERRORS),
        ];
        foreach (array_keys(self::STATUSES) as $key) {
            $settings[$this->statusKey($key)] = (string) Tools::getValue($this->statusKey($key));
        }

        return $settings;
    }

    /**
     * Checks and saves the settings form. Nothing is saved unless all of it
     * is good.
     *
     * @param array<string, string> $input
     *
     * @return array<string, string> what is wrong, by field ('address',
     *                               'paid-status', …); empty when saved
     */
    public function saveSettings(array $input): array
    {
        $errors = [];
        $address = ConnectLink::address((string) ($input[self::ADDRESS] ?? ''));
        if ($address === null) {
            $errors['address'] = $this->text('error_address');
        }
        $statuses = [];
        foreach (array_keys(self::STATUSES) as $key) {
            $id = (int) ($input[$this->statusKey($key)] ?? 0);
            if (!$id || !Validate::isLoadedObject(new OrderState($id))) {
                $errors[$key . '-status'] = $this->text('error_status');
            }
            $statuses[$this->statusKey($key)] = $id;
        }
        if ($errors) {
            return $errors;
        }

        Configuration::updateGlobalValue(self::ADDRESS, $address);
        Configuration::updateGlobalValue(self::FORWARD_ERRORS, empty($input[self::FORWARD_ERRORS]) ? 0 : 1);
        foreach ($statuses as $name => $id) {
            Configuration::updateGlobalValue($name, $id);
        }

        return [];
    }

    private function renderSettings(): string
    {
        $statuses = OrderState::getOrderStates((int) $this->context->language->id);
        $statusInputs = [];
        foreach (array_keys(self::STATUSES) as $key) {
            $statusInputs[] = [
                'type' => 'select',
                'label' => $this->text('status_' . $key),
                'desc' => $this->text('status_' . $key . '_help'),
                'name' => $this->statusKey($key),
                'required' => true,
                'options' => ['query' => $statuses, 'id' => 'id_order_state', 'name' => 'name'],
            ];
        }

        $forms = [
            ['form' => [
                'legend' => ['title' => $this->text('settings'), 'icon' => 'icon-cogs'],
                'input' => [
                    [
                        'type' => 'text',
                        'label' => $this->text('address'),
                        'desc' => $this->text('address_help'),
                        'name' => self::ADDRESS,
                        'required' => true,
                        'placeholder' => 'https://pay.example.com',
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->text('forward_errors'),
                        'desc' => $this->text('forward_errors_help'),
                        'name' => self::FORWARD_ERRORS,
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'forward_on', 'value' => 1, 'label' => $this->trans('Yes', [], 'Admin.Global')],
                            ['id' => 'forward_off', 'value' => 0, 'label' => $this->trans('No', [], 'Admin.Global')],
                        ],
                    ],
                ],
                'submit' => ['title' => $this->trans('Save', [], 'Admin.Actions')],
            ]],
            ['form' => [
                'legend' => ['title' => $this->text('statuses'), 'icon' => 'icon-list'],
                'description' => $this->text('statuses_help'),
                'input' => $statusInputs,
                'submit' => ['title' => $this->trans('Save', [], 'Admin.Actions')],
            ]],
        ];

        $values = [
            self::ADDRESS => Tools::getValue(self::ADDRESS, (string) Configuration::getGlobalValue(self::ADDRESS)),
            self::FORWARD_ERRORS => (int) Tools::getValue(self::FORWARD_ERRORS, (int) Configuration::getGlobalValue(self::FORWARD_ERRORS)),
        ];
        foreach (array_keys(self::STATUSES) as $key) {
            $values[$this->statusKey($key)] = (int) Tools::getValue($this->statusKey($key), $this->orderStatus($key));
        }

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->identifier = $this->identifier;
        $helper->table = $this->table;
        $helper->show_toolbar = false;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->submit_action = 'submitMonokulo';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false, [], ['configure' => $this->name]);
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = ['fields_value' => $values];

        return $helper->generateForm($forms);
    }

    /** The Stores panel: each shop, its connection, and its Connect button. */
    private function renderStores(string $back): string
    {
        $canConnect = (string) Configuration::getGlobalValue(self::ADDRESS) !== '';
        $stores = [];
        foreach (Shop::getShops(false, null, true) as $shopId) {
            $shop = new Shop((int) $shopId);
            $url = $this->shopUrl($shop);
            $connection = $this->repository()->connection(ConnectLink::host($url));
            $stores[] = [
                'id' => (int) $shop->id,
                'name' => $shop->name,
                'url' => $url,
                'connected' => $connection ? $this->text('connected_as', [
                    '%key%' => $connection->publicKey,
                    '%date%' => Tools::displayDate(date('Y-m-d H:i:s', $connection->connectedAt), true),
                ]) : '',
                'connect' => $back . '&monokulo_connect=' . (int) $shop->id,
            ];
        }

        $this->context->smarty->assign([
            'monokulo_stores' => $stores,
            'monokulo_can_connect' => $canConnect,
            'monokulo_text' => [
                'stores' => $this->text('stores'),
                'stores_help' => $this->text('stores_help'),
                'address_first' => $this->text('address_first'),
                'not_connected' => $this->text('not_connected'),
                'connect' => $this->text('connect'),
                'reconnect' => $this->text('reconnect'),
            ],
        ]);

        return $this->display(__FILE__, 'views/templates/admin/stores.tpl');
    }

    /* ------------------------------------------------------------------ */
    /* Connecting */
    /* ------------------------------------------------------------------ */

    /**
     * Starts connecting one shop: remembers a single-use nonce for it, and
     * says where to send the admin's browser to sign in to Monokulo and pick
     * the store. Monokulo comes back to the shop's own address (it only
     * returns to the shop's host), whose connect controller finishes
     * connecting and sends the browser back to `adminReturn`.
     *
     * @return array{redirect: string}|array{error: string}
     */
    public function startConnect(int $shopId, string $adminReturn): array
    {
        $address = (string) Configuration::getGlobalValue(self::ADDRESS);
        $shop = new Shop($shopId);
        if ($address === '' || !Validate::isLoadedObject($shop)) {
            return ['error' => 'address'];
        }
        $siteUrl = $this->shopUrl($shop);
        $returnUrl = $this->context->link->getModuleLink($this->name, 'connect', [], null, null, (int) $shop->id);
        $nonce = $this->repository()->createNonce((int) $shop->id, ConnectLink::host($siteUrl), $adminReturn, time());

        return ['redirect' => ConnectLink::start($address, $siteUrl, $returnUrl, $nonce)];
    }

    /**
     * The end of the connect flow. Monokulo sends the admin's browser back to
     * the shop's own address with the single-use `token` and the `nonce`
     * startConnect made. The nonce is used up whatever happens; when it was
     * this shop's and still fresh, the token is redeemed from the server for
     * the shop's credentials, and the browser goes back to the settings page
     * saying how it went.
     *
     * @return array{redirect: string}|array{status: int, body: string}
     */
    public function connectReturn(string $token, string $nonce): array
    {
        $attempt = $this->repository()->consumeNonce($nonce, time());
        if (!$attempt || $token === '') {
            $this->log()->warning('Connect return refused: no token, or a nonce this shop did not make, already used or expired.');

            return ['status' => 400, 'body' => $this->text('connect_failed')];
        }

        $host = $this->host();
        if ($attempt['host'] !== $host) {
            $this->log()->warning(sprintf('Connect return refused: started for %s but came back to %s.', $attempt['host'], $host));
            $result = 'monokulo_connect_error=host';
        } else {
            $result = $this->finishConnect($host, $token);
        }

        return ['redirect' => $attempt['admin_return'] . (str_contains($attempt['admin_return'], '?') ? '&' : '?') . $result];
    }

    /** Redeems the connect token; the query string telling the settings page how it went. */
    private function finishConnect(string $host, string $token): string
    {
        $address = (string) Configuration::getGlobalValue(self::ADDRESS);
        $webhookUrl = $this->webhookUrl($this->context->shop);

        try {
            $credentials = $this->client()->finishConnect($address, $token, $webhookUrl);
        } catch (ApiError $e) {
            $this->log()->error(sprintf('Connect finish at %s failed: HTTP %d: %s', $address, $e->status, $e->detail));

            return $e->status === 503 ? 'monokulo_connect_error=unavailable' : 'monokulo_connect_error=finish';
        } catch (TransportError $e) {
            $this->log()->error(sprintf('Connect finish at %s failed: could not reach it: %s', $address, $e->getMessage()));

            return 'monokulo_connect_error=unreachable';
        }

        $this->repository()->saveConnection(new Connection($host, $credentials['endpoint'], $credentials['public_key'], $credentials['secret_token'], $credentials['webhook_signing_secret'], time()));
        $this->log()->info(sprintf('%s connected to Monokulo as %s.', $host, $credentials['public_key']));

        return 'monokulo_connected=1';
    }

    /**
     * Where Monokulo sends a shop's webhooks: PrestaShop's plain module
     * address, which reaches the module whatever the shop's friendly URL and
     * language settings are, now or later.
     */
    public function webhookUrl(Shop $shop): string
    {
        return $this->shopUrl($shop) . 'index.php?' . http_build_query(['fc' => 'module', 'module' => $this->name, 'controller' => 'webhook']);
    }

    /* ------------------------------------------------------------------ */
    /* Checkout */
    /* ------------------------------------------------------------------ */

    /**
     * "Pay with Monero" at checkout, when this shop is connected and the
     * cart's currency is one the module takes. PrestaShop's own payment preferences decide countries, currencies
     * and customer groups.
     *
     * @param array{cart: Cart} $params
     *
     * @return list<PaymentOption>
     */
    public function hookPaymentOptions($params)
    {
        if (!$this->active || !$this->isConnected() || !$this->checkCurrency($params['cart'])) {
            return [];
        }

        $this->context->smarty->assign('monokulo_description', $this->text('checkout_description'));
        $option = new PaymentOption();
        $option->setModuleName($this->name)
            ->setCallToActionText($this->text('pay_with_monero'))
            ->setAction($this->context->link->getModuleLink($this->name, 'validation', [], true))
            ->setAdditionalInformation($this->fetch('module:monokulo/views/templates/hook/payment_option.tpl'));

        return [$option];
    }

    /** Whether the current shop is connected. */
    public function isConnected(): bool
    {
        return $this->connection() !== null;
    }

    /** Whether the module takes the cart's currency (PrestaShop's payment preferences). */
    public function checkCurrency(Cart $cart): bool
    {
        foreach ((array) $this->getCurrency((int) $cart->id_currency) as $currency) {
            if ((int) $currency['id_currency'] === (int) $cart->id_currency) {
                return true;
            }
        }

        return false;
    }

    /**
     * Places the cart's order, marked with the merchant's "order placed"
     * status (Awaiting Monero payment by default), and sends the customer to
     * pay it. The order confirmation email carries the shop's payment link,
     * which goes to the same Monokulo order. When Monokulo can't be reached
     * or refuses, the order stays placed and the customer can try again
     * through that link.
     *
     * @return array{redirect: string}|array{error: string, retry?: string}
     */
    public function placeOrder(Cart $cart, Customer $customer): array
    {
        if (!$this->isConnected()) {
            return ['error' => $this->text('error_not_connected')];
        }

        $this->validateOrder(
            (int) $cart->id,
            $this->orderStatus('pending'),
            (float) $cart->getOrderTotal(true, Cart::BOTH),
            $this->text('payment_name'),
            null,
            $this->confirmationMailVars((int) $cart->id, (string) $customer->secure_key),
            (int) $cart->id_currency,
            false,
            $customer->secure_key
        );

        return $this->pay(new Order((int) $this->currentOrder));
    }

    /**
     * What the order confirmation email says about the payment: the method,
     * with the shop's payment link.
     *
     * @return array<string, string>
     */
    public function confirmationMailVars(int $cartId, string $secureKey): array
    {
        return ['{payment}' => $this->text('mail_payment', ['%url%' => $this->payLink($cartId, $secureKey)])];
    }

    /**
     * The shop's payment link for an order, which works for as long as the
     * order is unpaid: in its confirmation email, on the order in the
     * customer's account, and on the order confirmation page.
     */
    public function payLink(int $cartId, string $secureKey): string
    {
        // PrestaShop 8 writes the parameters for HTML (&amp;): this is a plain address.
        return html_entity_decode($this->context->link->getModuleLink($this->name, 'pay', ['id_cart' => $cartId, 'key' => $secureKey], true), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * The payment link, followed: the order whose cart and key it names, sent
     * to pay.
     *
     * @return array{redirect: string}|array{error: string, retry?: string}
     */
    public function payFromLink(int $cartId, string $key): array
    {
        $order = Order::getByCartId($cartId);
        if (!$order instanceof Order || $order->module !== $this->name || $key === '' || !hash_equals((string) $order->secure_key, $key)) {
            return ['error' => $this->text('error_order')];
        }
        if ($order->hasBeenPaid() || !$this->isUnpaid($order)) {
            return ['error' => $this->text('error_not_payable')];
        }

        return $this->pay($order);
    }

    /**
     * Creates (or, for the same total, finds again) the Monokulo order for a
     * PrestaShop order, and says where to send the customer. The first time
     * a Monokulo order is seen, the order gets a private note with its link.
     *
     * @return array{redirect: string}|array{error: string, retry: string}
     */
    public function pay(Order $order): array
    {
        $connection = $this->connection(new Shop((int) $order->id_shop));
        $retry = $this->payLink((int) $order->id_cart, (string) $order->secure_key);
        if (!$connection) {
            return ['error' => $this->text('error_not_connected'), 'retry' => $retry];
        }

        $currency = new Currency((int) $order->id_currency);
        $names = [];
        foreach ($order->getProducts() as $product) {
            $names[] = (string) $product['product_name'];
        }
        $body = OrderRequest::body(
            (int) $order->id_shop,
            (int) $order->id,
            (string) $order->reference,
            (float) $order->total_paid,
            (string) $currency->iso_code,
            (int) $currency->precision,
            $names,
            $this->text('order_description', ['%reference%' => $order->reference]),
            $this->text('order_description_more')
        );

        try {
            $monokuloOrder = $this->client()->createOrder($connection, $body);
        } catch (ApiError $e) {
            $this->log()->error(sprintf('Creating the Monokulo order for order %d failed: HTTP %d: %s%s', $order->id, $e->status, $e->detail, $e->merchantHint()));
            $this->forward($connection);

            return ['error' => $this->text($e->customerMessageKey()), 'retry' => $retry];
        } catch (TransportError $e) {
            $this->log()->error(sprintf('Creating the Monokulo order for order %d failed: could not reach %s: %s', $order->id, $connection->ordersUrl(), $e->getMessage()));
            $this->forward($connection);

            return ['error' => $this->text('error_unreachable'), 'retry' => $retry];
        }

        $checkout = $connection->checkoutUrl($monokuloOrder['order_id']);
        if ($this->repository()->recordOrder($monokuloOrder['order_id'], (int) $order->id, (int) $order->id_shop, $connection->host, $body['amount'], $body['currency'], time())) {
            $this->orders()->note($order, $this->text('note_started', ['%url%' => $checkout]));
            $this->log()->info(sprintf('Order %d: customer sent to Monokulo order %s.', $order->id, $monokuloOrder['order_id']));
        }

        return ['redirect' => $checkout];
    }

    /** Whether the order is waiting for its Monero payment (one of the merchant's not-yet-paid statuses). */
    public function isUnpaid(Order $order): bool
    {
        $state = $order->getCurrentOrderState();
        $waiting = [$this->orderStatus('pending'), $this->orderStatus('waiting'), $this->orderStatus('partial')];

        return $state instanceof OrderState && !$state->paid && in_array((int) $state->id, $waiting, true);
    }

    /**
     * The order confirmation page: a way back to the payment while the order
     * is unpaid.
     *
     * @param array{order: Order} $params
     */
    public function hookDisplayPaymentReturn($params)
    {
        return $this->payButton($params['order'] ?? null);
    }

    /**
     * The order's page in the customer's account: a way back to the payment
     * while the order is unpaid.
     *
     * @param array{order: Order} $params
     */
    public function hookDisplayOrderDetail($params)
    {
        return $this->payButton($params['order'] ?? null);
    }

    private function payButton($order): string
    {
        if (!$this->active || !$order instanceof Order || $order->module !== $this->name || !$this->isUnpaid($order)) {
            return '';
        }
        $this->context->smarty->assign([
            'monokulo_pay_link' => $this->payLink((int) $order->id_cart, (string) $order->secure_key),
            'monokulo_pay_text' => $this->text('pay_order', ['%reference%' => $order->reference]),
            'monokulo_pay_button' => $this->text('pay_with_monero'),
        ]);

        return $this->fetch('module:monokulo/views/templates/hook/pay_button.tpl');
    }

    /* ------------------------------------------------------------------ */
    /* Webhooks */
    /* ------------------------------------------------------------------ */

    /**
     * One webhook delivery to this shop's host; the HTTP status to answer.
     * See WebhookReceiver for what each means.
     */
    public function receiveWebhook(string $body, string $signature, string $traceparent): int
    {
        $this->trace()->adopt($traceparent);
        $connection = $this->connection();
        $status = (new WebhookReceiver($this->repository(), $this->orders(), $this->log()))->handle($connection, $body, $signature, time());
        $this->forward($connection);

        return $status;
    }

    /* ------------------------------------------------------------------ */
    /* Plumbing */
    /* ------------------------------------------------------------------ */

    public function repository(): Repository
    {
        return new Repository(new PrestaShopDatabase());
    }

    public function orders(): Orders
    {
        return new Orders($this);
    }

    /** The order status the merchant chose for `key` (pending, waiting, partial, paid, expired). */
    public function orderStatus(string $key): int
    {
        return (int) Configuration::getGlobalValue($this->statusKey($key));
    }

    private function statusKey(string $key): string
    {
        return 'MONOKULO_OS_' . strtoupper($key);
    }

    /**
     * A shop's address, as Monokulo knows it (`https://shop.example/`): its
     * https domain when the shop uses https, as PrestaShop's own links do.
     */
    public function shopUrl(Shop $shop): string
    {
        $ssl = (bool) Configuration::get('PS_SSL_ENABLED', null, null, (int) $shop->id);

        return ($ssl ? 'https://' . $shop->domain_ssl : 'http://' . $shop->domain) . $shop->getBaseURI();
    }

    /** A shop's host (the current shop's by default), as its connection is kept. */
    public function host(?Shop $shop = null): string
    {
        return ConnectLink::host($this->shopUrl($shop ?? $this->context->shop));
    }

    /** A shop's connection (the current shop's by default), or null before it has connected. */
    public function connection(?Shop $shop = null): ?Connection
    {
        return $this->repository()->connection($this->host($shop));
    }

    /** The settings page's own address. */
    private function adminUrl(): string
    {
        return $this->context->link->getAdminLink('AdminModules', true, [], ['configure' => $this->name]);
    }

    private function client(): Client
    {
        return new Client($this->trace());
    }

    private function trace(): Trace
    {
        return $this->trace ??= new Trace();
    }

    /**
     * The module's log: var/logs/monokulo.log, and warnings and errors also
     * in PrestaShop's own log (Advanced Parameters > Logs).
     */
    public function log(): Logger
    {
        return $this->logger ??= new Logger(static function (string $line, string $level): void {
            file_put_contents(_PS_ROOT_DIR_ . '/var/logs/' . self::LOG_FILE, date('Y-m-d H:i:s') . ' ' . $line . "\n", FILE_APPEND | LOCK_EX);
            if ($level !== 'info') {
                PrestaShopLogger::addLog('Monokulo: ' . strtr($line, ['<' => '(', '>' => ')', '{' => '(', '}' => ')']), $level === 'error' ? 3 : 2, null, 'Monokulo', null, true);
            }
        }, $this->trace());
    }

    /** Sends this request's warnings and errors to Monokulo, when the merchant turned that on. */
    private function forward(?Connection $connection): void
    {
        if ($this->logger && $connection && Configuration::getGlobalValue(self::FORWARD_ERRORS)) {
            $this->client()->forwardLogs($connection, $this->logger->forwardable());
        }
    }

    /**
     * The module's words, in the shop's language, with `params` (`%url%` =>
     * …) put in. Customer-facing ones are in Modules.Monokulo.Shop, the
     * merchant's in Modules.Monokulo.Admin.
     *
     * @param array<string, string> $params
     */
    public function text(string $key, array $params = []): string
    {
        $shop = 'Modules.Monokulo.Shop';
        $admin = 'Modules.Monokulo.Admin';

        $message = match ($key) {
            // Checkout and the customer's pages
            'pay_with_monero' => $this->trans('Pay with Monero', [], $shop),
            'payment_name' => $this->trans('Monero (via Monokulo)', [], $shop),
            'checkout_description' => $this->trans('You pay on a secure Monokulo payment page next; the payment link is also in your order confirmation.', [], $shop),
            'mail_payment' => $this->trans('Monero (via Monokulo). Pay for your order here: %url%', [], $shop),
            'pay_order' => $this->trans('Your order %reference% is waiting for its Monero payment.', [], $shop),
            'order_description' => $this->trans('Order %reference%', [], $shop),
            'order_description_more' => $this->trans(' + %d more', [], $shop),
            'connect_failed' => $this->trans('This connect link has already been used or has expired. Start connecting again from the Monokulo module\'s settings in your PrestaShop back office.', [], $shop),
            'error_order' => $this->trans('This payment link is not valid. Please check the link in your order confirmation email.', [], $shop),
            'error_not_payable' => $this->trans('This order is not waiting for a payment.', [], $shop),
            'error_not_connected' => $this->trans('Monero payments are not set up for this store yet. Please contact the store owner.', [], $shop),
            'error_busy' => $this->trans('Monero payments are busy right now. Please wait a moment and try again.', [], $shop),
            'error_unavailable' => $this->trans('Monero payments are not available for this store right now. Please contact the store owner.', [], $shop),
            'error_generic' => $this->trans('The Monero payment could not be started. Please try again, or contact the store.', [], $shop),
            'error_unreachable' => $this->trans('The Monero payment service could not be reached. Please try again shortly.', [], $shop),
            'try_again' => $this->trans('Try again', [], $shop),
            'payment_problem' => $this->trans('Monero payment', [], $shop),
            // Order notes, for the merchant
            'note_started' => $this->trans('Monokulo: the customer was sent to pay this order with Monero here: %url%', [], $admin),
            'note_waiting' => $this->trans('Monokulo: a payment was seen and is waiting for confirmations.', [], $admin),
            'note_partial' => $this->trans('Monokulo: part of the amount due has arrived. The order is not paid in full yet.', [], $admin),
            'note_paid' => $this->trans('Monokulo: paid in full.', [], $admin),
            'note_overpaid' => $this->trans('Monokulo: paid, with more than the amount due. The excess is not refunded automatically: refund it yourself if needed.', [], $admin),
            'note_expired' => $this->trans('Monokulo: the payment window closed without full payment. Any funds received stay at the payment address; refund them yourself if needed.', [], $admin),
            'note_double_spend_detected' => $this->trans('Monokulo: FRAUD ALERT. A payment to this order was double-spent and no longer counts (transaction %transaction%). If this changed the order status, that is a separate entry. Check this order before sending anything.', [], $admin),
            'note_double_spend_reversed' => $this->trans('Monokulo: the earlier double-spend alert for this order (transaction %transaction%) was wrong and is withdrawn. If this changed the order status, that is a separate entry.', [], $admin),
            'note_superseded' => $this->trans('Monokulo: an older payment request for this order was paid, after the order total changed. Check the amount received before treating this order as paid.', [], $admin),
            'note_kept_paid' => $this->trans('The order was already paid, so its status is left as it is: check it before doing anything else.', [], $admin),
            'note_unknown_transaction' => $this->trans('unknown', [], $admin),
            'see_in_monokulo' => $this->trans('In Monokulo: %url%', [], $admin),
            // Settings
            'settings' => $this->trans('Monokulo settings', [], $admin),
            'settings_saved' => $this->trans('The Monokulo settings are saved.', [], $admin),
            'address' => $this->trans('Monokulo address', [], $admin),
            'address_help' => $this->trans('Your Monokulo\'s public address, as your browser opens it (https://pay.example.com).', [], $admin),
            'forward_errors' => $this->trans('Send errors to Monokulo', [], $admin),
            'forward_errors_help' => $this->trans('Also sends this module\'s warnings and errors to your Monokulo, when the store there accepts client logs. They are always written to var/logs/monokulo.log, and to Advanced Parameters > Logs.', [], $admin),
            'statuses' => $this->trans('Order statuses', [], $admin),
            'statuses_help' => $this->trans('Where an order goes as its Monero payment progresses. Monokulo announces each change; PrestaShop then does what it does on any status change (its email, the invoice, stock).', [], $admin),
            'status_pending' => $this->trans('Order placed', [], $admin),
            'status_pending_help' => $this->trans('When the customer is sent to pay. The payment link is in the order confirmation email.', [], $admin),
            'status_waiting' => $this->trans('Payment seen', [], $admin),
            'status_waiting_help' => $this->trans('A payment is on its way and waiting for confirmations.', [], $admin),
            'status_partial' => $this->trans('Part paid', [], $admin),
            'status_partial_help' => $this->trans('Less than the amount due has arrived so far.', [], $admin),
            'status_paid' => $this->trans('Paid', [], $admin),
            'status_paid_help' => $this->trans('Paid in full (or more). This status\'s email tells the customer.', [], $admin),
            'status_expired' => $this->trans('Expired', [], $admin),
            'status_expired_help' => $this->trans('The payment window closed without full payment.', [], $admin),
            'stores' => $this->trans('Stores', [], $admin),
            'stores_help' => $this->trans('Each shop connects to a store in your Monokulo, found by the shop\'s web address. Shops that share an address share one connection.', [], $admin),
            'connected_as' => $this->trans('Connected as %key% since %date%', [], $admin),
            'not_connected' => $this->trans('Not connected', [], $admin),
            'address_first' => $this->trans('Save your Monokulo address first.', [], $admin),
            'connected' => $this->trans('Your store is connected. Monero is now offered at checkout.', [], $admin),
            'connect' => $this->trans('Connect', [], $admin),
            'reconnect' => $this->trans('Connect again', [], $admin),
            'error_permission' => $this->trans('You do not have permission to change the Monokulo settings.', [], $admin),
            'error_address' => $this->trans('Enter an http or https address, such as https://pay.example.com.', [], $admin),
            'error_status' => $this->trans('Choose an order status.', [], $admin),
            'error_connect_address' => $this->trans('Save your Monokulo address before connecting.', [], $admin),
            'error_connect_permission' => $this->trans('You do not have permission to connect shops.', [], $admin),
            'error_connect_host' => $this->trans('The connect flow came back to a different shop than it started from. Please connect again from this page.', [], $admin),
            'error_connect_finish' => $this->trans('Monokulo did not hand over the store\'s keys. Please connect again; the log says why.', [], $admin),
            'error_connect_unavailable' => $this->trans('Your Monokulo cannot connect shops yet: its operator has not set its public address.', [], $admin),
            'error_connect_unreachable' => $this->trans('Your Monokulo could not be reached from this shop\'s server. Check the address, then connect again.', [], $admin),
        };

        // The values go in here, not through trans(): PrestaShop 8 escapes them
        // for HTML (&amp;), and these words also go into emails, order messages
        // and links.
        return strtr($message, $params);
    }
}
