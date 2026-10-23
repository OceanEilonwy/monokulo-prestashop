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
 * 0.2.0 talks to Monokulo's HTTP API (`/api/v1`). A shop connected with
 * 0.1.0 keeps its connection: its API entry is `/api/v1` on the saved
 * Monokulo address (Repository::install).
 *
 * @param Monokulo $module
 */
function upgrade_module_0_2_0($module)
{
    $module->repository()->install((string) Configuration::getGlobalValue(Monokulo::ADDRESS));

    return true;
}
