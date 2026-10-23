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
 * A shop host's link to a store in Monokulo, from the connect flow. A
 * Monokulo store is one host, so every PrestaShop shop on the same host shares
 * one connection.
 */
final class Connection
{
    /**
     * @param string $api the API's entry, an absolute URL
     *                    (`https://www.monokulo.com/api/v1`), which every other
     *                    API address is found from
     */
    public function __construct(
        public readonly string $host,
        public readonly string $api,
        public readonly string $publicKey,
        public readonly string $secretToken,
        public readonly string $webhookSecret,
        public readonly int $connectedAt,
    ) {
    }

    /**
     * Where a plugin's reports go: `logs` under the entry. The entry has
     * no link to it, so it is the one address not found by following
     * links.
     */
    public function logsUrl(): string
    {
        return rtrim($this->api, '/') . '/logs';
    }

    /** The order's page in the merchant's Monokulo, on the API's host. */
    public function dashboardOrderUrl(string $storeId, string $orderId): string
    {
        return Url::origin($this->api) . '/stores/' . rawurlencode($storeId) . '/orders/' . rawurlencode($orderId);
    }
}
