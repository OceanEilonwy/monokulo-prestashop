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
 * Who this plugin is, as monokulo sees it.
 */
final class Plugin
{
    /** Kept equal to VERSION and the module's own version by a test. */
    public const VERSION = '0.1.0';

    /** The connect path's platform slug: `/plugins/connect/prestashop`. */
    public const SLUG = 'prestashop';

    /** What monokulo shows the merchant ("Connect your PrestaShop shop"). */
    public const NAME = 'PrestaShop';

    /**
     * Bumped when a stored connection can no longer be used as is (its
     * credentials changed shape): the store must connect again, and Monero is
     * hidden at checkout until it does.
     */
    public const CONNECTION_VERSION = '1';

    /** The `Monokulo-Client` header value, `prestashop/<version>`. */
    public static function client(): string
    {
        return self::SLUG . '/' . self::VERSION;
    }
}
