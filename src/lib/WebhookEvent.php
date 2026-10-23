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
 * The parts of a webhook body (api_version 2) this plugin acts on. Fields it
 * doesn't need are ignored, and fields monokulo adds later don't break it.
 * `dashboard` is the order's page on the merchant's dashboard, the body's
 * `mk:dashboard-order` link (absolute, as every webhook link is), or null.
 */
final class WebhookEvent
{
    private function __construct(
        public readonly string $event,
        public readonly string $eventId,
        public readonly string $orderId,
        public readonly ?string $txid,
        public readonly ?string $dashboard,
    ) {
    }

    /** The event in `body`, or null when it isn't a webhook envelope. */
    public static function parse(string $body): ?self
    {
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return null;
        }
        foreach (['event', 'event_id', 'order_id'] as $key) {
            if (!isset($data[$key]) || !is_string($data[$key]) || $data[$key] === '') {
                return null;
            }
        }
        $text = static fn (string $key): ?string => isset($data[$key]) && is_string($data[$key]) && $data[$key] !== '' ? $data[$key] : null;

        $dashboard = $data['_links']['mk:dashboard-order']['href'] ?? null;

        return new self($data['event'], $data['event_id'], $data['order_id'], $text('txid'), is_string($dashboard) && $dashboard !== '' ? $dashboard : null);
    }
}
