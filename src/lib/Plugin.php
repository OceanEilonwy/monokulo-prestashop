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
 * Who this plugin is, as monokulo sees it.
 */
final class Plugin
{
    /** Kept equal to VERSION and the module's own version by a test. */
    public const VERSION = '0.3.0';

    /** The connect path's platform slug: `/plugins/connect/prestashop`. */
    public const SLUG = 'prestashop';

    /** What monokulo shows the merchant ("Connect your PrestaShop shop"). */
    public const NAME = 'PrestaShop';

    /** The `Monokulo-Client` header value, `prestashop/<version>`. */
    public static function client(): string
    {
        return self::SLUG . '/' . self::VERSION;
    }
}
