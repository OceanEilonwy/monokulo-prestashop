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
 * A shop host's link to a store in Monokulo, from the connect flow. A
 * Monokulo store is one host, so every PrestaShop shop on the same host shares
 * one connection.
 */
final class Connection
{
    public function __construct(
        public readonly string $host,
        public readonly string $endpoint,
        public readonly string $publicKey,
        public readonly string $secretToken,
        public readonly string $webhookSecret,
        public readonly string $version,
        public readonly int $connectedAt,
    ) {
    }

    /** Connected by an older plugin whose credentials no longer work as stored. */
    public function needsReconnect(): bool
    {
        return $this->version !== Plugin::CONNECTION_VERSION;
    }

    public function ordersUrl(): string
    {
        return rtrim($this->endpoint, '/') . '/pay/' . rawurlencode($this->publicKey) . '/orders';
    }

    /** Where the customer pays the order. */
    public function checkoutUrl(string $orderId): string
    {
        return $this->ordersUrl() . '/' . rawurlencode($orderId);
    }

    public function logsUrl(): string
    {
        return rtrim($this->endpoint, '/') . '/pay/' . rawurlencode($this->publicKey) . '/logs';
    }

    /** The order's page in the merchant's Monokulo dashboard. */
    public function dashboardOrderUrl(string $storeId, string $orderId): string
    {
        return rtrim($this->endpoint, '/') . '/dashboard/stores/' . rawurlencode($storeId) . '/orders/' . rawurlencode($orderId);
    }
}
