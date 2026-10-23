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
     *                    (`https://www.monokulo.com/api/v1`, or with the
     *                    path a Monokulo is served under), which every other
     *                    API address is found from
     * @param string $onionApi the same entry on Monokulo's onion address, or
     *                         '' when it had none when the shop connected
     * @param Entry|null $entry the entry as last read, or null before the first order
     */
    public function __construct(
        public readonly string $host,
        public readonly string $api,
        public readonly string $onionApi,
        public readonly string $publicKey,
        public readonly string $secretToken,
        public readonly string $webhookSecret,
        public readonly int $connectedAt,
        public readonly ?Entry $entry = null,
    ) {
    }
}
