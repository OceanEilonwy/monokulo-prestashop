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
 * 0.3.0 keeps the API entry between orders, each order's page on the
 * merchant's dashboard, and Monokulo's onion address. A shop on 0.2.0 keeps
 * its connection and orders (Repository::install adds the columns); its
 * connection learns the onion address when it connects again.
 *
 * @param Monokulo $module
 */
function upgrade_module_0_3_0($module)
{
    $module->repository()->install();

    return true;
}
