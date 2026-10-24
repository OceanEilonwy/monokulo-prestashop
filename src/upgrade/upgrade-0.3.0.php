<?php

/**
 * Monokulo for PrestaShop
 *
 * @author    Monokulo
 * @copyright Monokulo
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 0.3.0 keeps the API entry between orders, Monokulo's onion address, and
 * the last test webhook received.
 * A shop on 0.2.0 keeps its connection and orders (Repository::install adds
 * the columns, and drops the dashboard link an early 0.3.0 kept); its
 * connection learns the onion address when it connects again.
 *
 * @param Monokulo $module
 */
function upgrade_module_0_3_0($module)
{
    $module->repository()->install();

    return true;
}
