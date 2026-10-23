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

/** No answer came back from monokulo. */
final class TransportError extends \RuntimeException
{
}
