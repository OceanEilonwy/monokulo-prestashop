<?php

/**
 * Monokulo for PrestaShop
 *
 * @author    Monokulo
 * @copyright Monokulo
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 */

namespace Monokulo\PrestaShop;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The parts of a webhook body (api_version 2) this plugin acts on. Fields it
 * doesn't need are ignored, and fields monokulo adds later don't break it.
 */
final class WebhookEvent
{
    private function __construct(
        public readonly string $event,
        public readonly string $eventId,
        public readonly string $orderId,
        public readonly ?string $txid,
        public readonly ?string $storeId,
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
        $store = isset($data['store']) && is_array($data['store']) && isset($data['store']['id']) && is_string($data['store']['id']) ? $data['store']['id'] : null;

        return new self($data['event'], $data['event_id'], $data['order_id'], $text('txid'), $store);
    }
}
