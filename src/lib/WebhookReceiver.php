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
 * Handles one webhook delivery and says which HTTP status to answer.
 * Monokulo reads only the status: a 2xx is delivered, anything else is
 * retried with backoff.
 *
 * - 401: no connection for this shop host, or the signature doesn't check
 *   out (wrong secret, changed body, more than five minutes off).
 * - 400: correctly signed, but not a webhook envelope.
 * - 404: no order here for that Monokulo order (retried, in case the order
 *   is still being saved).
 * - 200: applied, or already applied before (a repeat changes nothing),
 *   or an event this version doesn't know (accepted and logged).
 * - 500: applying it failed; the event is given back so the retry applies it.
 */
final class WebhookReceiver
{
    public function __construct(
        private readonly Repository $repository,
        private readonly OrderUpdater $updater,
        private readonly Logger $log,
    ) {
    }

    public function handle(?Connection $connection, string $body, string $signature, int $now): int
    {
        if (!$connection || !Signature::verify($connection->webhookSecret, $body, $signature, $now)) {
            $this->log->warning('Webhook refused: no connection for this shop, or a missing or wrong X-Monokulo-Signature.');

            return 401;
        }
        $event = WebhookEvent::parse($body);
        if (!$event) {
            $this->log->error('Webhook refused: signed, but not a webhook envelope: ' . $body);

            return 400;
        }
        $order = $this->repository->findOrder($event->orderId);
        if (!$order) {
            $this->log->warning(sprintf('Webhook %s for Monokulo order %s: no such order in this shop.', $event->eventId, $event->orderId));

            return 404;
        }
        $decision = StatusPolicy::decide($event);
        if (!$decision) {
            $this->log->warning(sprintf('Webhook %s: unknown event "%s", accepted and ignored.', $event->eventId, $event->event));

            return 200;
        }
        if (!$this->repository->claimEvent($event->eventId, $event->orderId, $now)) {
            return 200;
        }
        try {
            $this->updater->apply($order['order_id'], $decision, $event, $order['latest'], $order['paid_elsewhere'], $order['dashboard']);
            if ($decision->paid) {
                $this->repository->markPaid($event->orderId, $now);
            }
        } catch (\Throwable $e) {
            $this->repository->releaseEvent($event->eventId);
            $this->log->error(sprintf('Webhook %s for order %d could not be applied: %s', $event->eventId, $order['order_id'], $e->getMessage()));

            return 500;
        }
        $this->log->info(sprintf('Webhook %s (%s) applied to order %d.', $event->eventId, $event->event, $order['order_id']));

        return 200;
    }
}
