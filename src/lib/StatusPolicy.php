<?php

/**
 * Monokulo for PrestaShop
 *
 * @author    Monokulo
 * @copyright Monokulo
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Monokulo\PrestaShop;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Maps a monokulo event to what happens to the PrestaShop order.
 *
 * - `order.unconfirmed`, `order.confirming`: a payment was seen and is
 *   waiting for confirmations. "Waiting" status (Awaiting Monero payment by default).
 * - `order.partial`: less than the amount due has arrived. "Partial"
 *   status (Awaiting Monero payment by default), with a note.
 * - `order.paid`: paid in full. "Paid" status (Payment accepted by default),
 *   whose email tells the customer.
 * - `order.overpaid`: paid with more than was due. Paid, plus a note that
 *   the excess isn't refunded automatically.
 * - `order.expired`: the payment window closed without full payment.
 *   "Expired" status (Canceled by default), with a note that any
 *   funds received stay at the payment address.
 * - `order.cancelled`: the merchant cancelled the order in Monokulo before
 *   anything was paid (only Monokulo's POS cancels, and only its own
 *   orders, so a shop order shouldn't see it). Handled as `order.expired`:
 *   the "Expired" status, with a note.
 * - `order.double_spend_detected`, `order.double_spend_reversed`: a note
 *   only. If the order's status changes because of it, monokulo announces
 *   that as its own `order.<status>` event.
 *
 * Anything else is accepted and ignored (null), so a newer monokulo can add
 * events without breaking the shop.
 */
final class StatusPolicy
{
    public static function decide(WebhookEvent $event): ?Decision
    {
        return match ($event->event) {
            'order.unconfirmed', 'order.confirming' => new Decision(Decision::STATUS_WAITING, 'note_waiting'),
            'order.partial' => new Decision(Decision::STATUS_PARTIAL, 'note_partial'),
            'order.paid' => new Decision(Decision::STATUS_PAID, 'note_paid', notify: true, paid: true),
            'order.overpaid' => new Decision(Decision::STATUS_PAID, 'note_overpaid', notify: true, paid: true),
            'order.expired' => new Decision(Decision::STATUS_EXPIRED, 'note_expired'),
            'order.cancelled' => new Decision(Decision::STATUS_EXPIRED, 'note_cancelled'),
            'order.double_spend_detected' => new Decision(null, 'note_double_spend_detected'),
            'order.double_spend_reversed' => new Decision(null, 'note_double_spend_reversed'),
            default => null,
        };
    }
}
