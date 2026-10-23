<?php
namespace Monokulo\PrestaShop\Tests\Support;

/**
 * Boots the installed PrestaShop in this PHP process, the way its front
 * index.php (or the back office's) does before dispatching: its config,
 * database, context (shop by host, language, currency, link) and modules.
 * A test then calls the module's methods and hooks directly.
 *
 * Every test shares PrestaShop's one database connection, so a test can run
 * in a transaction and roll it back (ShopTestCase). One process boots one
 * side: the back office defines constants the front must not have, so the
 * two run as separate PHPUnit suites.
 */
final class PrestaShop {
	public const ROOT = '/var/www/html/';

	/** @param 'front'|'admin' $side */
	public static function boot(string $side): void {
		$_SERVER = [
			'HTTP_HOST'       => Shop::HOST,
			'SERVER_NAME'     => 'localhost',
			'SERVER_PORT'     => '8093',
			'REQUEST_URI'     => '/',
			'SCRIPT_NAME'     => $side === 'admin' ? '/admin-dev/index.php' : '/index.php',
			'PHP_SELF'        => $side === 'admin' ? '/admin-dev/index.php' : '/index.php',
			'REQUEST_METHOD'  => 'GET',
			'REMOTE_ADDR'     => '127.0.0.1',
			'HTTP_USER_AGENT' => 'monokulo-prestashop-tests',
			'SERVER_PROTOCOL' => 'HTTP/1.1',
		] + $_SERVER;
		if ($side === 'admin') {
			define('_PS_ADMIN_DIR_', self::ROOT . 'admin-dev');
			define('PS_ADMIN_DIR', _PS_ADMIN_DIR_);
		}
		// config.inc.php sets up globals as PrestaShop's own entry points do.
		(static function (): void {
			require_once PrestaShop::ROOT . 'config/config.inc.php';
		})();
		if ($side === 'admin') {
			\Context::getContext()->employee = new \Employee(1);
		} else {
			// What a front controller sets up: its service container (prices,
			// taxes), and the visitor's currency.
			\Context::getContext()->container = \PrestaShop\PrestaShop\Adapter\ContainerBuilder::getContainer('front', _PS_MODE_DEV_);
		}
		\Context::getContext()->currency = new \Currency((int)\Configuration::get('PS_CURRENCY_DEFAULT'));
		\Context::getContext()->language = new \Language((int)\Configuration::get('PS_LANG_DEFAULT'));
		require_once self::ROOT . 'modules/monokulo/monokulo.php';
	}

	/**
	 * Makes `shopId` the current shop, as a request to its address would:
	 * the context's shop, its link, and PrestaShop's shop context.
	 */
	public static function useShop(int $shopId): \Context {
		$context = \Context::getContext();
		\Shop::setContext(\Shop::CONTEXT_SHOP, $shopId);
		$context->shop = new \Shop($shopId);
		$context->link = new \Link();

		return $context;
	}

	/** A fresh instance of the module, as one request gets (its own trace and log). */
	public static function module(): \Monokulo {
		return new \Monokulo();
	}
}
