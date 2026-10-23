<?php
namespace Monokulo\PrestaShop\Tests\Support;

/**
 * The PrestaShop under test (docker/compose.yaml): its address, its back
 * office login, and its database, for setting up and checking what a test
 * needs over HTTP.
 */
final class Shop {
	public const HOST = 'localhost:8093';
	public const URL = 'http://localhost:8093/';
	public const ADMIN = self::URL . 'admin-dev/';
	public const ADMIN_EMAIL = 'admin@example.test';
	public const ADMIN_PASSWORD = 'admin-password-1';

	private static ?\PDO $db = null;

	public static function db(): \PDO {
		return self::$db ??= new \PDO(
			'mysql:host=127.0.0.1;port=' . (int)(getenv('DB_PORT') ?: 33063) . ';dbname=prestashop;charset=utf8mb4',
			'prestashop',
			'prestashop',
			[\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_STRINGIFY_FETCHES => true],
		);
	}

	/** @return list<array<string, string|null>> */
	public static function rows(string $sql): array {
		return self::db()->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
	}

	public static function exec(string $sql): void {
		self::db()->exec($sql);
	}

	/** The id the last INSERT made. */
	public static function lastId(): int {
		return (int)self::db()->lastInsertId();
	}

	public static function quote(string $value): string {
		return self::db()->quote($value);
	}

	/** Sets a global configuration value, and empties PrestaShop's caches so Apache sees it. */
	public static function configure(string $name, string $value): void {
		self::exec("DELETE FROM ps_configuration WHERE name = " . self::quote($name) . " AND id_shop IS NOT NULL");
		if (self::rows("SELECT id_configuration FROM ps_configuration WHERE name = " . self::quote($name))) {
			self::exec("UPDATE ps_configuration SET value = " . self::quote($value) . ", date_upd = NOW() WHERE name = " . self::quote($name));
		} else {
			self::exec("INSERT INTO ps_configuration SET name = " . self::quote($name) . ", value = " . self::quote($value) . ", date_add = NOW(), date_upd = NOW()");
		}
	}

	public static function setting(string $name): ?string {
		return self::rows("SELECT value FROM ps_configuration WHERE name = " . self::quote($name) . " AND id_shop IS NULL")[0]['value'] ?? null;
	}

	/**
	 * Signs in to the back office as a merchant does, through its login form
	 * (PrestaShop 9's, or 8's older one).
	 */
	public static function admin(?Browser $browser = null): Browser {
		$browser ??= new Browser();
		$page = $browser->follow($browser->get(self::ADMIN . 'index.php?controller=AdminLogin'));
		if (str_contains($page->body, 'name="submit_login"')) {
			$done = $browser->submit($page, '/login', ['email' => self::ADMIN_EMAIL, 'passwd' => self::ADMIN_PASSWORD]);
			if ($done->status !== 302 || str_contains((string)$done->location(), '/login')) {
				throw new \RuntimeException('back office login failed: ' . $done->status . ' ' . substr($done->text(), 0, 500));
			}
		} else {
			$answer = $browser->post(self::ADMIN . 'index.php?rand=' . time(), [
				'ajax'        => '1',
				'controller'  => 'AdminLogin',
				'submitLogin' => '1',
				'email'       => self::ADMIN_EMAIL,
				'passwd'      => self::ADMIN_PASSWORD,
			])->json();
			if (!empty($answer['hasErrors'])) {
				throw new \RuntimeException('back office login failed: ' . json_encode($answer));
			}
		}

		return $browser;
	}

	/** A back office page by its legacy controller name, followed through PrestaShop's redirects (to its Symfony page). */
	public static function adminPage(Browser $browser, string $controller, array $query = []): Page {
		return $browser->follow($browser->get(self::ADMIN . 'index.php?' . http_build_query(['controller' => $controller] + $query)));
	}

	/**
	 * The signed-in employee's token for the back office's Symfony pages
	 * (`_token`), from where PrestaShop sends a legacy link.
	 */
	public static function routeToken(Browser $browser): string {
		$page = $browser->get(self::ADMIN . 'index.php?controller=AdminModulesManage');
		if (!preg_match('/[?&]_token=([^&]+)/', (string)$page->location(), $m)) {
			throw new \RuntimeException('no _token in ' . $page->status . ' ' . $page->location());
		}

		return urldecode($m[1]);
	}

	/** The module's settings page. */
	public static function settingsPage(Browser $browser, array $query = []): Page {
		return self::adminPage($browser, 'AdminModules', ['configure' => 'monokulo'] + $query);
	}

	/**
	 * Places an order as "Pay with Monero" does (Fixtures::placedOrder, in a
	 * PrestaShop booted by another PHP process, so it is committed for
	 * Apache to see); returns its id.
	 */
	public static function placeOrder(): int {
		$out = [];
		exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/make-order.php') . ' 2>&1', $out, $status);
		$id = (int)end($out);
		if ($status !== 0 || $id <= 0) {
			throw new \RuntimeException("make-order.php failed ($status): " . implode("\n", $out));
		}

		return $id;
	}

	/** Empties PrestaShop's caches, after changing its database behind its back. */
	public static function clearCache(): void {
		foreach (glob('/var/www/html/var/cache/*/smarty/cache/*') ?: [] as $file) {
			@unlink($file);
		}
	}
}
