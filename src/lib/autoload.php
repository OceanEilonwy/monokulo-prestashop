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

/*
 * Loads the module's own classes (namespace Monokulo\PrestaShop, one class
 * per file named after it). They know nothing about PrestaShop (except
 * Shop\, which is the module's glue to it), so the unit tests load them the
 * same way without booting a shop.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Monokulo\\PrestaShop\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
