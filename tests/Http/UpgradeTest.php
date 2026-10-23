<?php
namespace Monokulo\PrestaShop\Tests\Http;

use Monokulo\PrestaShop\Database;
use Monokulo\PrestaShop\Repository;
use Monokulo\PrestaShop\Tests\Support\Shop;
use PHPUnit\Framework\TestCase;

/**
 * A shop connected with 0.1.0, before Monokulo's HTTP API, kept the address
 * Monokulo gave it (`endpoint`) and no API entry. Upgrading
 * (upgrade/upgrade-0.2.0.php) or installing again over the kept tables
 * gives each connection its entry, `/api/v1` on the saved Monokulo address,
 * so the shop keeps taking Monero without connecting again.
 *
 * Against the shop's real database, in tables of their own (the schema
 * change can't run inside a rolled-back transaction).
 */
final class UpgradeTest extends TestCase {
	private const PREFIX = 'mkupgrade_';

	private function database(): Database {
		return new class(self::PREFIX) implements Database {
			private int $affected = 0;

			public function __construct(private readonly string $prefix) {
			}

			public function execute(string $sql): void {
				$this->affected = (int)Shop::db()->exec($sql);
			}

			public function row(string $sql): ?array {
				// As PrestaShop's Db::getRow does.
				$row = Shop::db()->query($sql . ' LIMIT 1')->fetch(\PDO::FETCH_ASSOC);

				return $row ?: null;
			}

			public function affected(): int {
				return $this->affected;
			}

			public function quote(string $value): string {
				return Shop::quote($value);
			}

			public function prefix(): string {
				return $this->prefix;
			}
		};
	}

	protected function setUp(): void {
		$this->dropTables();
		// 0.1.0's table, with two shops connected.
		Shop::exec("CREATE TABLE `mkupgrade_monokulo_connection` (
			`host` VARCHAR(255) NOT NULL,
			`endpoint` VARCHAR(255) NOT NULL,
			`public_key` VARCHAR(128) NOT NULL,
			`secret_token` VARCHAR(255) NOT NULL,
			`webhook_secret` VARCHAR(255) NOT NULL,
			`connected_at` INT(11) NOT NULL,
			PRIMARY KEY (`host`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
		Shop::exec("INSERT INTO `mkupgrade_monokulo_connection` VALUES
			('shop.example', 'https://pay.example.com/', 'pk_a', 'sk_a', 'whsec_a', 1760000000),
			('other.example', 'https://pay.example.com', 'pk_b', 'sk_b', 'whsec_b', 1760000001)");
		// And its orders, without when each was paid.
		Shop::exec("CREATE TABLE `mkupgrade_monokulo_order` (
			`seq` INT(11) NOT NULL AUTO_INCREMENT,
			`monokulo_order_id` VARCHAR(64) NOT NULL,
			`id_order` INT(10) UNSIGNED NOT NULL,
			`id_shop` INT(10) UNSIGNED NOT NULL,
			`host` VARCHAR(255) NOT NULL,
			`amount` VARCHAR(40) NOT NULL,
			`currency` VARCHAR(8) NOT NULL,
			`created_at` INT(11) NOT NULL,
			PRIMARY KEY (`monokulo_order_id`),
			UNIQUE KEY `seq` (`seq`),
			KEY `id_order` (`id_order`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
		Shop::exec("INSERT INTO `mkupgrade_monokulo_order` (`monokulo_order_id`, `id_order`, `id_shop`, `host`, `amount`, `currency`, `created_at`) VALUES
			('order_old', 7, 1, 'shop.example', '12.50', 'EUR', 1760000000)");
	}

	protected function tearDown(): void {
		$this->dropTables();
	}

	private function dropTables(): void {
		foreach (['connection', 'order', 'event', 'connect_nonce'] as $table) {
			Shop::exec('DROP TABLE IF EXISTS `' . self::PREFIX . 'monokulo_' . $table . '`');
		}
	}

	/** @return list<string> */
	private function columns(): array {
		return array_column(Shop::rows('SHOW COLUMNS FROM `mkupgrade_monokulo_connection`'), 'Field');
	}

	public function test_over_http_an_upgraded_connection_finds_the_api_on_the_saved_address(): void {
		$repository = new Repository($this->database());

		$repository->install('https://www.monokulo.com/');

		$this->assertSame(['host', 'api', 'public_key', 'secret_token', 'webhook_secret', 'connected_at'], $this->columns(), 'the old endpoint is gone');
		$connection = $repository->connection('shop.example');
		$this->assertSame(['https://www.monokulo.com/api/v1', 'pk_a', 'sk_a', 'whsec_a', 1760000000], [$connection->api, $connection->publicKey, $connection->secretToken, $connection->webhookSecret, $connection->connectedAt]);
		$this->assertSame('https://www.monokulo.com/api/v1', $repository->connection('other.example')->api);

		// Again (the next install, or a second upgrade run) changes nothing.
		$repository->install('https://elsewhere.example');
		$this->assertSame('https://www.monokulo.com/api/v1', $repository->connection('shop.example')->api);
		$this->assertSame(['host', 'api', 'public_key', 'secret_token', 'webhook_secret', 'connected_at'], $this->columns());
	}

	/** Its Monokulo orders are still found, and now note when each is paid. */
	public function test_over_http_upgraded_orders_note_their_payment(): void {
		$repository = new Repository($this->database());

		$repository->install('https://www.monokulo.com');

		$this->assertSame(['order_id' => 7, 'shop_id' => 1, 'host' => 'shop.example', 'latest' => true, 'paid_elsewhere' => false], $repository->findOrder('order_old'));
		$repository->markPaid('order_old', 1760000100);
		$this->assertSame('1760000100', Shop::rows("SELECT `paid_at` FROM `mkupgrade_monokulo_order` WHERE `monokulo_order_id` = 'order_old'")[0]['paid_at']);
	}

	/** Uninstalling forgot the address but kept the tables: the old endpoint stands in for it. */
	public function test_over_http_reinstalling_without_an_address_uses_the_old_endpoint(): void {
		$repository = new Repository($this->database());

		$repository->install('');

		$this->assertSame('https://pay.example.com/api/v1', $repository->connection('shop.example')->api);
		$this->assertSame('https://pay.example.com/api/v1', $repository->connection('other.example')->api);
	}
}
