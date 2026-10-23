<?php
namespace Monokulo\PrestaShop\Tests\Http;

use Monokulo\PrestaShop\Connection;
use Monokulo\PrestaShop\Database;
use Monokulo\PrestaShop\Entry;
use Monokulo\PrestaShop\Repository;
use Monokulo\PrestaShop\Tests\Support\Shop;
use PHPUnit\Framework\TestCase;

/**
 * A shop on 0.2.0, the first version on Monokulo's HTTP API, upgrades
 * (upgrade/upgrade-0.3.0.php, or installing again over the kept tables)
 * without connecting again: its connections and orders are kept, and get
 * the columns 0.3.0 added. Its connection has no onion address until it
 * connects again; an order made before has no dashboard link until the
 * customer goes back to pay it.
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
		// 0.2.0's tables, with a shop connected and an order.
		Shop::exec("CREATE TABLE `mkupgrade_monokulo_connection` (
			`host` VARCHAR(255) NOT NULL,
			`api` VARCHAR(255) NOT NULL,
			`public_key` VARCHAR(128) NOT NULL,
			`secret_token` VARCHAR(255) NOT NULL,
			`webhook_secret` VARCHAR(255) NOT NULL,
			`connected_at` INT(11) NOT NULL,
			PRIMARY KEY (`host`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
		Shop::exec("INSERT INTO `mkupgrade_monokulo_connection` VALUES
			('shop.example', 'https://example.com/monokulo/api/v1', 'pk_a', 'sk_a', 'whsec_a', 1760000000)");
		Shop::exec("CREATE TABLE `mkupgrade_monokulo_order` (
			`seq` INT(11) NOT NULL AUTO_INCREMENT,
			`monokulo_order_id` VARCHAR(64) NOT NULL,
			`id_order` INT(10) UNSIGNED NOT NULL,
			`id_shop` INT(10) UNSIGNED NOT NULL,
			`host` VARCHAR(255) NOT NULL,
			`amount` VARCHAR(40) NOT NULL,
			`currency` VARCHAR(8) NOT NULL,
			`created_at` INT(11) NOT NULL,
			`paid_at` INT(11) NULL DEFAULT NULL,
			PRIMARY KEY (`monokulo_order_id`),
			UNIQUE KEY `seq` (`seq`),
			KEY `id_order` (`id_order`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
		Shop::exec("INSERT INTO `mkupgrade_monokulo_order` (`monokulo_order_id`, `id_order`, `id_shop`, `host`, `amount`, `currency`, `created_at`) VALUES
			('order_old', 7, 1, 'shop.example', '12.5', 'EUR', 1760000000)");
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
	private function columns(string $table): array {
		return array_column(Shop::rows('SHOW COLUMNS FROM `mkupgrade_monokulo_' . $table . '`'), 'Field');
	}

	public function test_over_http_an_upgraded_connection_keeps_working(): void {
		$repository = new Repository($this->database());

		$repository->install();

		$this->assertSame(['host', 'api', 'onion_api', 'public_key', 'secret_token', 'webhook_secret', 'connected_at', 'entry', 'entry_etag', 'entry_expires_at'], $this->columns('connection'));
		$connection = $repository->connection('shop.example');
		$this->assertSame(['https://example.com/monokulo/api/v1', '', 'pk_a', 'sk_a', 'whsec_a', 1760000000, null], [$connection->api, $connection->onionApi, $connection->publicKey, $connection->secretToken, $connection->webhookSecret, $connection->connectedAt, $connection->entry]);

		// Again (the next install, or a second upgrade run) changes nothing.
		$repository->install();
		$this->assertEquals($connection, $repository->connection('shop.example'));
	}

	/** Its Monokulo orders are still found; one paid again from the shop gets its dashboard link. */
	public function test_over_http_upgraded_orders_are_still_found(): void {
		$repository = new Repository($this->database());

		$repository->install();

		$this->assertContains('dashboard', $this->columns('order'));
		$this->assertSame(['order_id' => 7, 'shop_id' => 1, 'host' => 'shop.example', 'dashboard' => '', 'latest' => true, 'paid_elsewhere' => false], $repository->findOrder('order_old'));
		$this->assertFalse($repository->recordOrder('order_old', 7, 1, 'shop.example', '12.5', 'EUR', 'https://example.com/monokulo/stores/s/orders/order_old', 1760000050));
		$this->assertSame('https://example.com/monokulo/stores/s/orders/order_old', $repository->findOrder('order_old')['dashboard']);
		$repository->markPaid('order_old', 1760000100);
		$this->assertSame('1760000100', Shop::rows("SELECT `paid_at` FROM `mkupgrade_monokulo_order` WHERE `monokulo_order_id` = 'order_old'")[0]['paid_at']);
	}

	/** The API entry is kept for the key it was read with: connecting again (a new key) forgets it. */
	public function test_over_http_the_entry_is_kept_per_key(): void {
		$repository = new Repository($this->database());
		$repository->install();
		$connection = $repository->connection('shop.example');

		$repository->keepEntry($connection, new Entry(['_links' => ['self' => ['href' => '/monokulo/api/v1']]], '"v1"', 1760003600));

		$kept = $repository->connection('shop.example')->entry;
		$this->assertEquals(new Entry(['_links' => ['self' => ['href' => '/monokulo/api/v1']]], '"v1"', 1760003600), $kept);

		$replaced = new Connection('shop.example', $connection->api, 'http://abc.onion/api/v1', 'pk_b', 'sk_b', 'whsec_b', 1760000200);
		$repository->saveConnection($replaced);
		$repository->keepEntry($connection, new Entry([], '"stale"', 1760009999));
		$this->assertNull($repository->connection('shop.example')->entry, 'an entry read with the old key is not kept for the new one');
		$this->assertSame('http://abc.onion/api/v1', $repository->connection('shop.example')->onionApi);
	}
}
