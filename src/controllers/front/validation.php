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

/**
 * "Pay with Monero" at checkout: places the order and sends the customer to
 * pay it on Monokulo, or says why not.
 *
 * @property Monokulo $module
 */
class MonokuloValidationModuleFrontController extends ModuleFrontController
{
    /** @var array{error: string, retry?: string}|null */
    private ?array $monokuloFailure = null;

    public function postProcess()
    {
        $cart = $this->context->cart;
        if (!$cart->id || !$cart->id_customer || !$cart->id_address_delivery || !$cart->id_address_invoice || !$this->module->active) {
            Tools::redirect($this->context->link->getPageLink('order', null, null, ['step' => 1]));
        }

        // Still offered: the customer may have changed their address or
        // currency just before paying.
        $offered = false;
        foreach (Module::getPaymentModules() as $module) {
            $offered = $offered || $module['name'] === $this->module->name;
        }
        $customer = new Customer((int) $cart->id_customer);
        if (!$offered || !$this->module->checkCurrency($cart) || !Validate::isLoadedObject($customer)) {
            Tools::redirect($this->context->link->getPageLink('order', null, null, ['step' => 1]));
        }

        $answer = $this->module->placeOrder($cart, $customer);
        if (isset($answer['redirect'])) {
            Tools::redirect($answer['redirect']);
        }
        $this->monokuloFailure = $answer;
    }

    public function initContent()
    {
        parent::initContent();
        $this->context->smarty->assign([
            'monokulo_title' => $this->module->text('payment_problem'),
            'monokulo_error' => $this->monokuloFailure['error'] ?? '',
            'monokulo_retry' => $this->monokuloFailure['retry'] ?? '',
            'monokulo_try_again' => $this->module->text('try_again'),
        ]);
        $this->setTemplate('module:monokulo/views/templates/front/error.tpl');
    }
}
