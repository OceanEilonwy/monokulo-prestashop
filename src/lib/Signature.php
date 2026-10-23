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
 * Checks an `X-Monokulo-Signature` header: `t=<unix seconds>,v1=<hex>`,
 * where the hex is HMAC-SHA256 of `<t>.<raw body>` keyed with the webhook's
 * signing secret. A signature more than five minutes from now is refused,
 * so a captured delivery can't be replayed later.
 */
final class Signature
{
    public const TOLERANCE_SECONDS = 300;

    public static function verify(string $secret, string $body, string $header, int $now): bool
    {
        if ($secret === '' || !preg_match('/\At=(\d{1,18}),v1=([0-9a-fA-F]{64})\z/', trim($header), $parts)) {
            return false;
        }
        if (abs($now - (int) $parts[1]) > self::TOLERANCE_SECONDS) {
            return false;
        }
        $expected = hash_hmac('sha256', $parts[1] . '.' . $body, $secret);

        return hash_equals($expected, strtolower($parts[2]));
    }
}
