<?php
// Composer's autoloader (PHPUnit and the tests' own classes), then the
// module's library from src/, exactly as the shop loads it.
//
// The front and admin suites run inside the installed PrestaShop, booted in
// this process (Support\PrestaShop); the others only need the constant every
// module file checks for.
require_once __DIR__ . '/../vendor/autoload.php';

$suite = getenv('MONOKULO_SUITE') ?: '';
if ($suite === 'front' || $suite === 'admin') {
	Monokulo\PrestaShop\Tests\Support\PrestaShop::boot($suite);
} elseif (!defined('_PS_VERSION_')) {
	define('_PS_VERSION_', 'none (tests without PrestaShop)');
}
require_once __DIR__ . '/../src/lib/autoload.php';
