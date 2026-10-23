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
 * Monokulo's webhooks for this shop's host, registered when it connected.
 * The answer is only a status: see WebhookReceiver for what each means.
 *
 * @property Monokulo $module
 */
class MonokuloWebhookModuleFrontController extends ModuleFrontController
{
    private int $answerStatus = 200;

    public function __construct()
    {
        parent::__construct();
        // Answered by displayAjax, with no page around it.
        $this->ajax = true;
    }

    public function postProcess()
    {
        $this->answerStatus = $this->module->receiveWebhook(
            (string) file_get_contents('php://input'),
            (string) ($_SERVER['HTTP_X_MONOKULO_SIGNATURE'] ?? ''),
            (string) ($_SERVER['HTTP_TRACEPARENT'] ?? '')
        );
    }

    public function initContent()
    {
    }

    public function displayAjax()
    {
        http_response_code($this->answerStatus);
        header('Content-Type: text/plain; charset=utf-8');
        $this->ajaxRender((string) $this->answerStatus);
    }
}
