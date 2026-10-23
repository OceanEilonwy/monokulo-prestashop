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
 * The shop's payment link for an order (`id_cart` and the order's `key`),
 * from the order confirmation email or the customer's account: sends the
 * customer to pay it on Monokulo while it is unpaid.
 *
 * @property Monokulo $module
 */
class MonokuloPayModuleFrontController extends ModuleFrontController
{
    /** @var array{error: string, retry?: string}|null */
    private ?array $monokuloFailure = null;

    public function postProcess()
    {
        $answer = $this->module->payFromLink((int) Tools::getValue('id_cart'), (string) Tools::getValue('key'));
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
