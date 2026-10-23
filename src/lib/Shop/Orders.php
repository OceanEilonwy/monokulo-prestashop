<?php

/**
 * Monokulo for PrestaShop
 *
 * @author    Monokulo
 * @copyright Monokulo
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Monokulo\PrestaShop\Shop;

use Monokulo\PrestaShop\Decision;
use Monokulo\PrestaShop\OrderUpdater;
use Monokulo\PrestaShop\WebhookEvent;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Applies webhook decisions to PrestaShop orders: a status change through
 * the order history, so PrestaShop does what it does on any status change
 * (the status's email, invoice and payment for a paid status, stock back for
 * a cancelled one), and a note as a private message on the order, which the
 * merchant reads on the order's page in the back office.
 */
final class Orders implements OrderUpdater
{
    public function __construct(
        private readonly \Monokulo $module,
    ) {
    }

    /**
     * - Paid (`order.paid`, `order.overpaid`) from any of the order's Monokulo
     *   orders marks it paid: the money arrived, whichever payment request
     *   it went to.
     * - Paid when another of its Monokulo orders was paid already: a second
     *   payment, a note only, for the merchant to refund.
     * - Anything else from a Monokulo order that is not the current one: a
     *   note only.
     * - A paid order never goes back: a later event is a note.
     */
    public function apply(int $orderId, Decision $decision, WebhookEvent $event, bool $latest, bool $paidElsewhere, string $dashboard): void
    {
        $order = new \Order($orderId);
        if (!\Validate::isLoadedObject($order)) {
            throw new \RuntimeException('order ' . $orderId . ' no longer exists');
        }
        $link = $dashboard !== '' ? ' ' . $this->module->text('see_in_monokulo', ['%url%' => $dashboard]) : '';
        $transaction = $event->txid ?? $this->module->text('note_unknown_transaction');
        $note = $this->module->text($decision->note, ['%transaction%' => $transaction]);
        if (!$latest) {
            $note .= ' ' . $this->module->text('note_earlier_request');
        }

        if ($decision->paid && $paidElsewhere) {
            $this->note($order, $this->module->text('note_paid_twice', ['%transaction%' => $transaction]) . $link);

            return;
        }

        if ($decision->status === null || (!$decision->paid && !$latest)) {
            $this->note($order, $note . $link);

            return;
        }

        $status = $this->module->orderStatus($decision->status);
        if (!$status) {
            throw new \RuntimeException('no order status is set for "' . $decision->status . '"');
        }

        // A paid order stays paid: PrestaShop has recorded its payment and
        // invoice, and Monokulo's advice is never to undo a payment on your own.
        $current = $order->getCurrentOrderState();
        if (!$decision->paid && $current instanceof \OrderState && $current->paid) {
            $this->note($order, $note . ' ' . $this->module->text('note_kept_paid') . $link);

            return;
        }

        if ((int) $order->current_state !== $status) {
            $this->changeStatus($order, $status);
        }
        $this->note($order, $note . $link);
    }

    /** Moves `order` to `status`, sending that status's email if it has one. */
    public function changeStatus(\Order $order, int $status): void
    {
        $history = new \OrderHistory();
        $history->id_order = (int) $order->id;
        $history->changeIdOrderState($status, $order, true);
        if (!$history->addWithemail(true)) {
            throw new \RuntimeException('the order history refused status ' . $status);
        }
    }

    /**
     * Adds `text` to the order's messages as a private one: only the merchant
     * sees it, on the order's page in the back office.
     */
    public function note(\Order $order, string $text): void
    {
        $customer = new \Customer((int) $order->id_customer);
        $threadId = (int) \CustomerThread::getIdCustomerThreadByEmailAndIdOrder($customer->email, (int) $order->id);
        if (!$threadId) {
            $thread = new \CustomerThread();
            $thread->id_contact = 0;
            $thread->id_customer = (int) $order->id_customer;
            $thread->id_shop = (int) $order->id_shop;
            $thread->id_order = (int) $order->id;
            $thread->id_lang = (int) $order->id_lang;
            $thread->email = $customer->email;
            $thread->status = 'open';
            $thread->token = \Tools::passwdGen(12);
            if (!$thread->add()) {
                throw new \RuntimeException('could not start the order\'s message thread');
            }
            $threadId = (int) $thread->id;
        }

        $message = new \CustomerMessage();
        $message->id_customer_thread = $threadId;
        $message->id_employee = 0;
        $message->message = $text;
        $message->private = 1;
        if (!$message->add()) {
            throw new \RuntimeException('could not add a message to the order');
        }
    }
}
