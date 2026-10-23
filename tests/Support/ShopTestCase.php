<?php
namespace Monokulo\PrestaShop\Tests\Support;

use Monokulo\PrestaShop\Connection;
use Monokulo\PrestaShop\Repository;
use Monokulo\PrestaShop\Shop\PrestaShopDatabase;
use PHPUnit\Framework\TestCase;

/**
 * A test against the installed PrestaShop, booted in this process. Each test
 * runs in a database transaction that is rolled back afterwards, so tests
 * leave the shop as they found it. A stand-in Monokulo runs for the class.
 */
abstract class ShopTestCase extends TestCase {
	public const HOST = Shop::HOST;
	public const SECOND_HOST = '127.0.0.1:8093';

	/** A mug from PrestaShop's demo catalogue, and its price in the demo shop's currency (GBP), tax included. */
	public const MUG = 6;

	protected static FakeMonokulo $monokulo;

	public static function setUpBeforeClass(): void {
		self::$monokulo = new FakeMonokulo();
	}

	public static function tearDownAfterClass(): void {
		self::$monokulo->stop();
	}

	protected function setUp(): void {
		self::$monokulo->reset();
		$this->forget();
		$this->db()->execute('START TRANSACTION');
		foreach (['connection', 'connect_nonce', 'order', 'event'] as $table) {
			$this->db()->execute('DELETE FROM `' . _DB_PREFIX_ . 'monokulo_' . $table . '`');
		}
		$this->setting(\Monokulo::ADDRESS, self::$monokulo->url);
		$this->setting(\Monokulo::FORWARD_ERRORS, '0');
		$awaiting = (int)\Configuration::getGlobalValue(\Monokulo::AWAITING_STATUS);
		foreach (\Monokulo::STATUSES as $key => $default) {
			$this->setting('MONOKULO_OS_' . strtoupper($key), (string)($default === null ? $awaiting : \Configuration::getGlobalValue($default)));
		}
		PrestaShop::newRequest();
	}

	protected function tearDown(): void {
		$this->db()->execute('ROLLBACK');
		$this->forget();
	}

	/** Drops what PrestaShop keeps in memory between requests, which a rollback makes stale. */
	private function forget(): void {
		\Cache::clean('*');
		\Configuration::clearConfigurationCacheForTesting();
		\Configuration::loadConfiguration();
		\Shop::resetStaticCache();
		\Shop::cacheShops(true);
	}

	protected function db(): \Db {
		return \Db::getInstance();
	}

	protected function module(): \Monokulo {
		return PrestaShop::module();
	}

	protected function repository(): Repository {
		return new Repository(new PrestaShopDatabase());
	}

	/** @return list<array<string, string|null>> as strings, whichever driver PrestaShop's database uses */
	protected function rows(string $sql): array {
		return array_map(static fn(array $row): array => array_map(static fn($v): ?string => $v === null ? null : (string)$v, $row), $this->db()->executeS($sql) ?: []);
	}

	/** Sets a global setting, as saving the settings page would. */
	protected function setting(string $key, string $value): void {
		\Configuration::updateGlobalValue($key, $value);
	}

	/**
	 * Connects `host` to the stand-in Monokulo, as the connect flow would:
	 * its API entry at `api` on it, and `onionApi` when it has an onion address.
	 */
	protected function connect(string $host = self::HOST, string $api = '/api/v1', string $onionApi = ''): Connection {
		$connection = new Connection($host, self::$monokulo->url . $api, $onionApi, 'pk_test', 'sk_test', Vectors::secret(), 1760000000);
		$this->repository()->saveConnection($connection);

		return $connection;
	}

	/** A second shop, on another host of this same PrestaShop (multistore on, as it must be for one). */
	protected function addSecondShop(): int {
		$this->setting('PS_MULTISHOP_FEATURE_ACTIVE', '1');
		$this->db()->execute("INSERT INTO `" . _DB_PREFIX_ . "shop` SET `id_shop_group` = 1, `name` = 'Second shop', `color` = '', `id_category` = 2, `theme_name` = 'classic', `active` = 1, `deleted` = 0");
		$shopId = (int)$this->db()->Insert_ID();
		$this->db()->execute("INSERT INTO `" . _DB_PREFIX_ . "shop_url` SET `id_shop` = " . $shopId . ", `domain` = '" . self::SECOND_HOST . "', `domain_ssl` = '" . self::SECOND_HOST . "', `physical_uri` = '/', `virtual_uri` = '', `main` = 1, `active` = 1");
		// What PrestaShop copies from the first shop when it makes one: which
		// modules it runs, and their payment preferences.
		foreach (['module_shop', 'module_currency', 'module_country', 'module_group', 'hook_module'] as $table) {
			$columns = array_column($this->rows('SHOW COLUMNS FROM `' . _DB_PREFIX_ . $table . '`'), 'Field');
			$select = implode(', ', array_map(static fn(string $c): string => $c === 'id_shop' ? (string)$shopId : '`' . $c . '`', $columns));
			$this->db()->execute('INSERT IGNORE INTO `' . _DB_PREFIX_ . $table . '` (`' . implode('`, `', $columns) . '`) SELECT ' . $select . ' FROM `' . _DB_PREFIX_ . $table . '` WHERE `id_shop` = 1');
		}
		\Shop::resetStaticCache();
		\Shop::cacheShops(true);

		return $shopId;
	}

	/**
	 * A guest's cart, ready to pay, as the context's cart.
	 *
	 * @param list<int> $products
	 */
	protected function cart(array $products = [self::MUG], int $quantity = 1): \Cart {
		return Fixtures::cart($products, $quantity);
	}

	/**
	 * An order as "Pay with Monero" places it, before Monokulo is asked for
	 * anything: in the merchant's "order placed" status.
	 */
	protected function placedOrder(array $products = [self::MUG]): \Order {
		return Fixtures::placedOrder($products);
	}

	protected function orderStatus(int $orderId): int {
		return (int)(new \Order($orderId))->getCurrentState();
	}

	/** @return list<int> the order's statuses, oldest first */
	protected function statusHistory(int $orderId): array {
		return array_map('intval', array_column($this->rows('SELECT `id_order_state` FROM `' . _DB_PREFIX_ . 'order_history` WHERE `id_order` = ' . $orderId . ' ORDER BY `id_order_history`'), 'id_order_state'));
	}

	/** @return list<string> the order's private messages (the module's notes), oldest first */
	protected function notes(int $orderId): array {
		return array_column($this->rows(
			'SELECT cm.`message` FROM `' . _DB_PREFIX_ . 'customer_message` cm JOIN `' . _DB_PREFIX_ . 'customer_thread` ct ON ct.`id_customer_thread` = cm.`id_customer_thread`'
			. ' WHERE ct.`id_order` = ' . $orderId . ' AND cm.`private` = 1 ORDER BY cm.`id_customer_message`'
		), 'message');
	}

	/** The module's log lines written since `before` bytes. */
	protected function logSince(int $before): string {
		$file = _PS_ROOT_DIR_ . '/var/logs/' . \Monokulo::LOG_FILE;

		return is_file($file) ? substr((string)file_get_contents($file), $before) : '';
	}

	protected function logSize(): int {
		$file = _PS_ROOT_DIR_ . '/var/logs/' . \Monokulo::LOG_FILE;
		clearstatcache();

		return is_file($file) ? (int)filesize($file) : 0;
	}
}
