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
 * Where the connect flow comes back, on the shop's own address, with
 * Monokulo's single-use `token` and the settings page's `nonce`: see
 * Monokulo::connectReturn.
 *
 * @property Monokulo $module
 */
class MonokuloConnectModuleFrontController extends ModuleFrontController
{
    private string $refusal = '';

    public function __construct()
    {
        parent::__construct();
        // Answered by displayAjax, with no page around it.
        $this->ajax = true;
    }

    public function postProcess()
    {
        $answer = $this->module->connectReturn((string) Tools::getValue('token'), (string) Tools::getValue('nonce'));
        if (isset($answer['redirect'])) {
            Tools::redirect($answer['redirect']);
        }
        $this->refusal = $answer['body'];
    }

    public function initContent()
    {
    }

    public function displayAjax()
    {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        $this->ajaxRender($this->refusal);
    }
}
