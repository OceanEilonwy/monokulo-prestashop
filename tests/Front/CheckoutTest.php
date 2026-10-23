<?php
namespace Monokulo\PrestaShop\Tests\Front;

use Monokulo\PrestaShop\Connection;
use Monokulo\PrestaShop\Entry;
use Monokulo\PrestaShop\Plugin;
use Monokulo\PrestaShop\Tests\Support\Api;
use Monokulo\PrestaShop\Tests\Support\FakeMonokulo;
use Monokulo\PrestaShop\Tests\Support\PrestaShop;
use Monokulo\PrestaShop\Tests\Support\ShopTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Monero at checkout: when it's offered, "Pay with Monero" placing the order
 * and sending the customer to pay, and the payment link that brings them
 * back to it, against real PrestaShop carts and orders and a stand-in
 * Monokulo.
 */
final class CheckoutTest extends ShopTestCase {
	/** @return list<\PrestaShop\PrestaShop\Core\Payment\PaymentOption> */
	private function options(?\Cart $cart = null): array {
		return $this->module()->hookPaymentOptions(['cart' => $cart ?? $this->cart()]);
	}

	/** Monokulo's API makes order `orderId`, or refuses with `status` and `body`. */
	private function monokuloAnswers(string $orderId, int $status = 201, mixed $body = null): void {
		self::$monokulo->api($orderId, $status, $body);
	}

	/** The request that made (or tried to make) the order: the entry's create-order form. */
	private function orderRequest(): array {
		$requests = self::$monokulo->requests();
		$this->assertSame(['GET /api/v1', 'POST /api/v1/orders'], array_map(static fn(array $r): string => $r['method'] . ' ' . $r['path'], array_slice($requests, 0, 2)));

		return $requests[1];
	}

	/** "Pay with Monero" for `cart`, as the checkout's last step sends it. */
	private function pay(\Cart $cart): array {
		return $this->module()->placeOrder($cart, new \Customer((int)$cart->id_customer));
	}

	public function test_monero_is_offered_once_the_shop_is_connected(): void {
		$this->assertSame([], $this->options(), 'not connected yet');

		$this->connect();

		$options = $this->options();
		$this->assertCount(1, $options);
		$this->assertSame('monokulo', $options[0]->getModuleName());
		$this->assertSame('Pay with Monero', $options[0]->getCallToActionText());
		$this->assertMatchesRegularExpression('#\Ahttp://' . self::HOST . '/([a-z]{2}/)?module/monokulo/validation\z#', $options[0]->getAction());
		$this->assertStringContainsString('You pay on a secure Monokulo payment page next', $options[0]->getAdditionalInformation());
	}

	/** PrestaShop's payment preferences decide the currencies. */
	public function test_a_currency_the_merchant_turned_off_hides_monero(): void {
		$this->connect();
		$cart = $this->cart();
		$this->db()->execute('DELETE FROM `' . _DB_PREFIX_ . 'module_currency` WHERE `id_module` = ' . (int)$this->module()->id . ' AND `id_currency` = ' . (int)$cart->id_currency);

		$this->assertSame([], $this->options($cart));
	}

	public function test_a_disabled_module_offers_nothing(): void {
		$this->connect();
		$module = $this->module();
		$module->active = false;

		$this->assertSame([], $module->hookPaymentOptions(['cart' => $this->cart()]));
	}

	/** Each shop is its own host and connects on its own. */
	public function test_a_second_shop_on_another_host_is_not_connected_by_the_first(): void {
		$shopId = $this->addSecondShop();
		$this->connect(self::HOST);
		$cart = $this->cart();

		$this->assertNotSame([], $this->options($cart));
		PrestaShop::useShop($shopId);
		$this->assertSame([], $this->options($cart));

		$this->connect(self::SECOND_HOST);
		$this->assertNotSame([], $this->options($cart));
	}

	/**
	 * Pay with Monero places the order (Awaiting Monero payment), creates its
	 * Monokulo order in the order's currency, notes the payment link on the
	 * order for the merchant, and sends the customer to pay.
	 */
	public function test_paying_places_the_order_and_sends_the_customer_to_monokulo(): void {
		$this->connect();
		$this->monokuloAnswers('order_42');
		$cart = $this->cart([1, self::MUG, 7]);

		$answer = $this->pay($cart);

		$checkout = self::$monokulo->url . '/checkout/order_42';
		$this->assertSame(['redirect' => $checkout], $answer);

		$order = \Order::getByCartId((int)$cart->id);
		$this->assertInstanceOf(\Order::class, $order);
		$this->assertSame('monokulo', $order->module);
		$this->assertSame('Monero (via Monokulo)', $order->payment);
		$this->assertSame($this->module()->orderStatus('pending'), $this->orderStatus((int)$order->id), 'placed, awaiting its Monero payment');
		$this->assertSame('Awaiting Monero payment', (new \OrderState($this->module()->orderStatus('pending'), 1))->name);

		$request = $this->orderRequest();
		$this->assertSame('Bearer sk_test', self::$monokulo->requests()[0]['headers']['authorization'], 'the entry, as the store\'s key sees it');
		$this->assertSame('Bearer sk_test', $request['headers']['authorization']);
		$this->assertSame('prestashop/' . Plugin::VERSION, $request['headers']['monokulo-client']);
		$amount = rtrim(rtrim(number_format((float)$order->total_paid, 2, '.', ''), '0'), '.');
		$this->assertSame([
			'amount'                 => $amount,
			'currency'               => 'GBP',
			'merchant_order_id'      => $order->reference,
			'show_merchant_order_id' => false,
			'description'            => 'Order ' . $order->reference . ': Hummingbird printed t-shirt + 2 more',
			'idempotency_key'        => 'ps:1:' . $order->id . ':' . $amount . ':GBP',
		], json_decode($request['body'], true));

		// The merchant's link to the order in Monokulo comes with its webhooks, not from here.
		$this->assertSame(['Monokulo: the customer was sent to pay this order with Monero here: ' . $checkout], $this->notes((int)$order->id));
		$this->assertSame([['monokulo_order_id' => 'order_42', 'id_order' => (string)$order->id, 'id_shop' => '1', 'host' => self::HOST, 'amount' => $amount, 'currency' => 'GBP']], $this->rows('SELECT `monokulo_order_id`, `id_order`, `id_shop`, `host`, `amount`, `currency` FROM `' . _DB_PREFIX_ . 'monokulo_order`'));
		$this->assertNotContains('dashboard', array_column($this->rows('SHOW COLUMNS FROM `' . _DB_PREFIX_ . 'monokulo_order`'), 'Field'), 'nothing keeps the dashboard link');
	}

	/** A Monokulo served under a path: the module follows its links there. */
	public function test_a_monokulo_under_a_path_is_followed_there(): void {
		$this->connect(api: '/monokulo/api/v1');
		self::$monokulo->api('order_42', prefix: '/monokulo');

		$answer = $this->pay($this->cart());

		$this->assertSame(['redirect' => self::$monokulo->url . '/monokulo/checkout/order_42'], $answer);
		$this->assertSame(['/monokulo/api/v1', '/monokulo/api/v1/orders'], array_column(self::$monokulo->requests(), 'path'));
	}

	/**
	 * A customer on the shop's .onion address stays on Tor: they go to the
	 * order's onion checkout, never to Monokulo's web address.
	 */
	public function test_a_customer_on_the_shops_onion_address_pays_over_tor(): void {
		$this->connect(onionApi: Api::ONION . '/api/v1');
		$this->monokuloAnswers('order_42');
		$_SERVER['HTTP_HOST'] = 'shopxyz.onion';
		$cart = $this->cart();

		try {
			$answer = $this->pay($cart);
		} finally {
			$_SERVER['HTTP_HOST'] = self::HOST;
		}

		$this->assertSame(['redirect' => Api::ONION . '/checkout/order_42'], $answer);
		$order = \Order::getByCartId((int)$cart->id);
		$this->assertSame(['Monokulo: the customer was sent to pay this order with Monero here: ' . Api::ONION . '/checkout/order_42'], $this->notes((int)$order->id));
	}

	/**
	 * A Monokulo without an onion address (its entry has no `mk:onion-api`):
	 * a Tor customer is told, not sent to the web, and the merchant's log
	 * says why. No Monokulo order is made, to expire unused.
	 */
	public function test_a_customer_on_tor_is_not_sent_to_the_web(): void {
		$this->connect();
		self::$monokulo->api('order_42', onion: false);
		$_SERVER['HTTP_HOST'] = 'shopxyz.onion:80';
		$cart = $this->cart();
		$before = $this->logSize();

		try {
			$answer = $this->pay($cart);
		} finally {
			$_SERVER['HTTP_HOST'] = self::HOST;
		}

		$order = \Order::getByCartId((int)$cart->id);
		$this->assertSame(['error' => 'Monero payments can\'t be made over Tor at this store right now. Please contact the store.', 'retry' => $this->module()->payLink((int)$cart->id, $order->secure_key)], $answer);
		$this->assertStringContainsString('your Monokulo has no onion address', $this->logSince($before));
		$this->assertSame($this->module()->orderStatus('pending'), $this->orderStatus((int)$order->id), 'placed, to pay another way');
		$this->assertSame(['GET /api/v1'], array_map(static fn(array $r): string => $r['method'] . ' ' . $r['path'], self::$monokulo->requests()), 'no order was made');
		$this->assertSame([], $this->rows('SELECT * FROM `' . _DB_PREFIX_ . 'monokulo_order`'));
	}

	/** Monokulo gained an onion address after the shop kept its entry: the entry is checked again, and the customer goes to the onion checkout. */
	public function test_a_kept_entry_from_before_monokulo_had_an_onion_address_is_checked_again(): void {
		$connection = $this->connect();
		$this->repository()->keepEntry($connection, new Entry(Api::entry(onion: false), '"entry-0"', time() + 3600));
		$this->monokuloAnswers('order_42');
		$_SERVER['HTTP_HOST'] = 'shopxyz.onion';

		try {
			$answer = $this->pay($this->cart());
		} finally {
			$_SERVER['HTTP_HOST'] = self::HOST;
		}

		$this->assertSame(['redirect' => Api::ONION . '/checkout/order_42'], $answer);
		$this->assertSame('"entry-0"', self::$monokulo->requests()[0]['headers']['if-none-match']);
	}

	/** An order without its onion checkout (an entry kept from before) still never sends a Tor customer to the web. */
	public function test_an_order_without_an_onion_checkout_is_refused_over_tor_too(): void {
		$this->connect();
		self::$monokulo->entry(Api::entry());
		self::$monokulo->respond('POST', '/api/v1/orders', 201, Api::order('order_42', onion: false));
		$_SERVER['HTTP_HOST'] = 'shopxyz.onion';

		try {
			$answer = $this->pay($this->cart());
		} finally {
			$_SERVER['HTTP_HOST'] = self::HOST;
		}

		$this->assertSame('Monero payments can\'t be made over Tor at this store right now. Please contact the store.', $answer['error']);
	}

	/** On the shop's web address, the web checkout, whether or not Monokulo has an onion address. */
	public function test_a_customer_on_the_web_pays_on_the_web(): void {
		$this->connect(onionApi: Api::ONION . '/api/v1');
		$this->monokuloAnswers('order_42');

		$this->assertSame(['redirect' => self::$monokulo->url . '/checkout/order_42'], $this->pay($this->cart()));
	}

	/**
	 * The order confirmation email names the payment method with the shop's
	 * payment link: PaymentModule::validateOrder puts these over the email's
	 * own variables.
	 */
	public function test_the_confirmation_email_carries_the_payment_link(): void {
		$cart = $this->cart();
		$key = (new \Customer((int)$cart->id_customer))->secure_key;

		$vars = $this->module()->confirmationMailVars((int)$cart->id, $key);

		$this->assertMatchesRegularExpression('#\AMonero \(via Monokulo\)\. Pay for your order here: http://' . self::HOST . '/([a-z]{2}/)?module/monokulo/pay\?id_cart=' . $cart->id . '&key=' . $key . '\z#', $vars['{payment}']);
		$this->assertSame(['{payment}'], array_keys($vars));
	}

	/** A product name with characters a shop would escape goes to Monokulo as the merchant typed it. */
	public function test_product_names_are_sent_as_typed(): void {
		$this->connect();
		$this->monokuloAnswers('order_1');
		$this->db()->execute('UPDATE `' . _DB_PREFIX_ . "product_lang` SET `name` = 'Tea & \"Biscuits\"' WHERE `id_product` = " . self::MUG);
		$cart = $this->cart();

		$this->pay($cart);

		$this->assertStringEndsWith(': Tea & "Biscuits"', json_decode($this->orderRequest()['body'], true)['description']);
	}

	public static function failures(): array {
		return [
			'busy'          => [429, Api::problem('rate-limited', 429, 'Too many requests', 'slow down'), 'Monero payments are busy right now. Please wait a moment and try again.', 'HTTP 429: slow down (rate limited'],
			'key rejected'  => [401, Api::recordedProblem('get', '/api/v1', 'unauthorized'), 'Monero payments are not available for this store right now. Please contact the store owner.', 'connect the store again'],
			'not priced'    => [400, Api::recordedProblem('post', '/api/v1/orders', 'invalid-amount'), 'This order cannot be paid in Monero right now. Please choose another payment method, or contact the store.', 'HTTP 400: ' . Api::recordedProblem('post', '/api/v1/orders', 'invalid-amount')['detail'] . ' (Monokulo cannot price this order'],
			'no rate'       => [503, Api::problem('rates-unavailable', 503, 'No exchange rate right now', 'no GBP rate') + ['retry_after' => 30], 'Monero payments can\'t be started at this moment. Please try again shortly: your order is kept.', 'HTTP 503: no GBP rate (Monokulo has no exchange rate for this currency right now'],
			'engine busy'   => [503, Api::problem('engine-unavailable', 503, 'Payment engine unavailable', 'busy'), 'Monero payments can\'t be started at this moment. Please try again shortly: your order is kept.', 'HTTP 503: busy (Monokulo\'s payment engine is unavailable right now. No Monokulo order was made'],
			'anything else' => [500, 'oops', 'The Monero payment could not be started. Please try again, or contact the store.', 'HTTP 500: oops'],
		];
	}

	/**
	 * Monokulo said no: the customer is told in their terms and can try again
	 * through the payment link, the merchant's log says why, and the order
	 * stays placed with no Monokulo order.
	 */
	#[DataProvider('failures')]
	public function test_a_refused_payment_leaves_the_order_placed_to_try_again(int $status, mixed $body, string $customer, string $logged): void {
		$this->connect();
		$this->monokuloAnswers('', $status, $body);
		$cart = $this->cart();
		$before = $this->logSize();

		$answer = $this->pay($cart);

		$order = \Order::getByCartId((int)$cart->id);
		$this->assertSame(['error' => $customer, 'retry' => $this->module()->payLink((int)$cart->id, $order->secure_key)], $answer);
		$this->assertSame($this->module()->orderStatus('pending'), $this->orderStatus((int)$order->id));
		$this->assertSame([], $this->notes((int)$order->id));
		$this->assertSame([], $this->rows('SELECT * FROM `' . _DB_PREFIX_ . 'monokulo_order`'));
		$this->assertStringContainsString($logged, $this->logSince($before));
	}

	/** Warnings and errors also go to PrestaShop's own log, for merchants who look there. */
	public function test_errors_are_also_in_prestashops_log(): void {
		$this->connect();
		$this->monokuloAnswers('', 500, Api::problem('internal', 500, 'Internal error', '<b>broken</b>'));

		$this->pay($this->cart());

		$logged = $this->rows('SELECT `message`, `severity`, `object_type` FROM `' . _DB_PREFIX_ . "log` WHERE `object_type` = 'Monokulo' ORDER BY `id_log` DESC LIMIT 1")[0];
		$this->assertSame('3', (string)$logged['severity']);
		$this->assertStringContainsString('HTTP 500: (b)broken(/b)', $logged['message']);
	}

	public function test_an_unreachable_monokulo_says_so(): void {
		$this->repository()->saveConnection(new Connection(self::HOST, 'http://127.0.0.1:' . FakeMonokulo::freePort() . '/api/v1', '', 'pk_test', 'sk_test', 'wh', 1));

		$answer = $this->pay($this->cart());

		$this->assertSame('The Monero payment service could not be reached. Please try again shortly.', $answer['error']);
	}

	/** Not connected: nothing is placed, and the cart is still there to pay another way. */
	public function test_paying_on_a_shop_that_is_not_connected_places_nothing(): void {
		$cart = $this->cart();

		$this->assertSame(['error' => 'Monero payments are not set up for this store yet. Please contact the store owner.'], $this->pay($cart));
		$this->assertNull(\Order::getByCartId((int)$cart->id));
		$this->assertSame([], self::$monokulo->requests());
	}

	/**
	 * The payment link asks Monokulo again with the same idempotency key, so
	 * a retry after a lost answer, or a second visit, gets the same Monokulo
	 * order; the merchant's note is written once.
	 */
	public function test_the_payment_link_gets_the_same_monokulo_order(): void {
		$this->connect();
		$this->monokuloAnswers('', 500, 'lost');
		$cart = $this->cart();
		$this->pay($cart);
		$order = \Order::getByCartId((int)$cart->id);

		$this->monokuloAnswers('order_1');
		$first = $this->module()->payFromLink((int)$cart->id, $order->secure_key);
		$second = $this->module()->payFromLink((int)$cart->id, $order->secure_key);

		$this->assertSame(['redirect' => self::$monokulo->url . '/checkout/order_1'], $first);
		$this->assertSame($first, $second);
		$posts = array_filter(self::$monokulo->requests(), static fn(array $r): bool => $r['method'] === 'POST');
		$keys = array_map(static fn(array $r): string => json_decode($r['body'], true)['idempotency_key'], $posts);
		$this->assertCount(3, $keys);
		$this->assertCount(1, array_unique($keys));
		$this->assertCount(1, $this->notes((int)$order->id));
	}

	/**
	 * No exchange rate, then one: trying again from the payment link sends
	 * the same request, with the same idempotency key, and makes the order.
	 */
	public function test_trying_again_after_no_rate_sends_the_same_request(): void {
		$this->connect();
		$this->monokuloAnswers('', 503, Api::problem('rates-unavailable', 503, 'No exchange rate right now', 'no GBP rate'));
		$cart = $this->cart();
		$this->assertArrayHasKey('error', $this->pay($cart));
		$order = \Order::getByCartId((int)$cart->id);

		$this->monokuloAnswers('order_1');
		$this->assertSame(['redirect' => self::$monokulo->url . '/checkout/order_1'], $this->module()->payFromLink((int)$cart->id, $order->secure_key));

		$posts = array_values(array_filter(self::$monokulo->requests(), static fn(array $r): bool => $r['method'] === 'POST'));
		$this->assertCount(2, $posts);
		$this->assertSame($posts[0]['body'], $posts[1]['body'], 'the same order, the same idempotency key');
		$this->assertCount(1, $this->notes((int)$order->id));
	}

	public function test_the_payment_link_needs_the_orders_own_key(): void {
		$this->connect();
		$order = $this->placedOrder();
		$module = $this->module();

		$this->assertSame(['error' => 'This payment link is not valid. Please check the link in your order confirmation email.'], $module->payFromLink((int)$order->id_cart, 'wrong'));
		$this->assertSame(['error' => 'This payment link is not valid. Please check the link in your order confirmation email.'], $module->payFromLink((int)$order->id_cart, ''));
		$this->assertSame(['error' => 'This payment link is not valid. Please check the link in your order confirmation email.'], $module->payFromLink(999999, $order->secure_key));
		$this->assertSame([], self::$monokulo->requests());
	}

	/** Another payment module's order is not this link's to pay. */
	public function test_the_payment_link_is_only_for_monero_orders(): void {
		$this->connect();
		$order = $this->placedOrder();
		$this->db()->execute('UPDATE `' . _DB_PREFIX_ . "orders` SET `module` = 'ps_wirepayment' WHERE `id_order` = " . (int)$order->id);
		\Cache::clean('*');

		$this->assertArrayHasKey('error', $this->module()->payFromLink((int)$order->id_cart, $order->secure_key));
		$this->assertSame([], self::$monokulo->requests());
	}

	public function test_a_paid_or_cancelled_order_is_not_paid_again(): void {
		$this->connect();
		$order = $this->placedOrder();
		$this->module()->orders()->changeStatus($order, $this->module()->orderStatus('paid'));

		$this->assertSame(['error' => 'This order is not waiting for a payment.'], $this->module()->payFromLink((int)$order->id_cart, $order->secure_key));

		$cancelled = $this->placedOrder();
		$this->module()->orders()->changeStatus($cancelled, $this->module()->orderStatus('expired'));
		$this->assertSame(['error' => 'This order is not waiting for a payment.'], $this->module()->payFromLink((int)$cancelled->id_cart, $cancelled->secure_key));
		$this->assertSame([], self::$monokulo->requests());
	}

	/** With "Send errors to Monokulo" on, a failure is also reported to Monokulo; off, it isn't. */
	public function test_errors_are_forwarded_only_when_turned_on(): void {
		$this->connect();
		$slowDown = Api::problem('rate-limited', 429, 'Too many requests', 'slow down');
		$this->monokuloAnswers('', 429, $slowDown);
		self::$monokulo->respond('POST', '/api/v1/logs', 204, '');

		$this->pay($this->cart());
		$this->assertSame(['/api/v1', '/api/v1/orders'], array_column(self::$monokulo->requests(), 'path'));

		self::$monokulo->reset();
		$this->monokuloAnswers('', 429, $slowDown);
		self::$monokulo->respond('POST', '/api/v1/logs', 204, '');
		$this->setting(\Monokulo::FORWARD_ERRORS, '1');

		$this->pay($this->cart());
		$requests = self::$monokulo->requests();
		$this->assertSame(['/api/v1/orders', '/api/v1/logs'], array_column($requests, 'path'), 'the entry, kept from the first order, has the send-logs form');
		$this->assertSame('Bearer sk_test', $requests[1]['headers']['authorization']);
		$entries = json_decode($requests[1]['body'], true)['entries'];
		$this->assertSame('error', $entries[0]['level']);
		$this->assertStringContainsString('HTTP 429: slow down', $entries[0]['message']);
		$this->assertSame(substr($requests[0]['headers']['traceparent'], 3, 32), $entries[0]['trace_id'], 'the same trace as the failed call');
	}

	/** On the order confirmation page and the order in the customer's account: back to the payment while unpaid. */
	public function test_the_customers_pages_offer_the_payment_while_unpaid(): void {
		$this->connect();
		$order = $this->placedOrder();
		$module = $this->module();
		$link = htmlspecialchars($module->payLink((int)$order->id_cart, $order->secure_key), ENT_QUOTES);

		foreach (['hookDisplayOrderDetail', 'hookDisplayPaymentReturn'] as $hook) {
			$html = $module->$hook(['order' => $order]);
			$this->assertStringContainsString('Your order ' . $order->reference . ' is waiting for its Monero payment.', $html, $hook);
			$this->assertStringContainsString('href="' . $link . '"', $html, $hook);
		}

		$module->orders()->changeStatus($order, $module->orderStatus('paid'));
		$this->assertSame('', $module->hookDisplayOrderDetail(['order' => new \Order((int)$order->id)]));
	}

	public function test_another_modules_order_gets_no_payment_button(): void {
		$order = $this->placedOrder();
		$order->module = 'ps_wirepayment';

		$this->assertSame('', $this->module()->hookDisplayOrderDetail(['order' => $order]));
	}
}
