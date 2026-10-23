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
 * Addresses in Monokulo's answers. Its links are paths on its own host
 * (`/api/v1/orders`), so each is resolved against the address it came
 * from: a shop that reaches Monokulo at one address stays on it.
 */
final class Url
{
    /** `ref` (an absolute URL, `//host/path`, `/path` or `path`) resolved against `base`. */
    public static function resolve(string $base, string $ref): string
    {
        if (preg_match('#\A[a-z][a-z0-9+.-]*:#i', $ref)) {
            return $ref;
        }
        $scheme = (string) parse_url($base, PHP_URL_SCHEME);
        if (str_starts_with($ref, '//')) {
            return $scheme . ':' . $ref;
        }
        if (str_starts_with($ref, '/')) {
            return self::origin($base) . $ref;
        }
        $path = (string) parse_url($base, PHP_URL_PATH);

        return self::origin($base) . substr($path, 0, (int) strrpos('/' . $path, '/') - 1) . '/' . $ref;
    }

    /** `https://host:port` of `url`. */
    public static function origin(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return '';
        }

        return strtolower($parts['scheme']) . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }
}
