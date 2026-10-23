<?php
namespace Monokulo\PrestaShop\Tests\Admin;

use Monokulo\PrestaShop\Tests\Support\ShopTestCase;

/**
 * The settings page's work, as the back office runs it: saving the
 * settings, and starting a shop's connect flow. (The page itself, as a
 * merchant sees it, is tested over HTTP.)
 */
final class SettingsTest extends ShopTestCase {
	private const ADMIN = 'http://localhost:8093/admin-dev/index.php?controller=AdminModules&configure=monokulo&token=abc';

	/** @return array<string, string> */
	private function form(array $fields = []): array {
		return $fields + [
			'MONOKULO_ADDRESS'        => 'https://pay.example.com/',
			'MONOKULO_FORWARD_ERRORS' => '0',
			'MONOKULO_OS_PENDING'     => (string)$this->module()->orderStatus('pending'),
			'MONOKULO_OS_WAITING'     => (string)$this->module()->orderStatus('waiting'),
			'MONOKULO_OS_PARTIAL'     => (string)$this->module()->orderStatus('partial'),
			'MONOKULO_OS_PAID'        => (string)\Configuration::get('PS_OS_PAYMENT'),
			'MONOKULO_OS_EXPIRED'     => (string)\Configuration::get('PS_OS_CANCELED'),
		];
	}

	/** @return array<string, string> */
	private function settings(): array {
		$out = [];
		foreach (['MONOKULO_ADDRESS', 'MONOKULO_FORWARD_ERRORS', 'MONOKULO_OS_PENDING', 'MONOKULO_OS_WAITING', 'MONOKULO_OS_PARTIAL', 'MONOKULO_OS_PAID', 'MONOKULO_OS_EXPIRED'] as $key) {
			$out[$key] = (string)\Configuration::getGlobalValue($key);
		}

		return $out;
	}

	public function test_saving_keeps_every_setting_and_cleans_the_address(): void {
		$bankWire = (string)\Configuration::get('PS_OS_BANKWIRE');

		$errors = $this->module()->saveSettings($this->form(['MONOKULO_FORWARD_ERRORS' => '1', 'MONOKULO_OS_PAID' => $bankWire, 'not_ours' => 'x']));

		$this->assertSame([], $errors);
		$this->assertEquals($this->form([
			'MONOKULO_ADDRESS'        => 'https://pay.example.com',
			'MONOKULO_FORWARD_ERRORS' => '1',
			'MONOKULO_OS_PAID'        => $bankWire,
		]), $this->settings());
	}

	public function test_saving_refuses_a_bad_address_or_a_missing_status(): void {
		$before = $this->settings();

		$errors = $this->module()->saveSettings($this->form(['MONOKULO_ADDRESS' => 'pay.example.com', 'MONOKULO_OS_PAID' => '', 'MONOKULO_OS_EXPIRED' => '99999']));

		$this->assertSame(['address', 'paid-status', 'expired-status'], array_keys($errors));
		$this->assertSame('Enter an http or https address, such as https://pay.example.com.', $errors['address']);
		$this->assertSame($before, $this->settings(), 'nothing saved');
	}

	/**
	 * Connect: a single-use nonce for the shop, then off to Monokulo with the
	 * shop's address, its own return address and the plugin's name.
	 */
	public function test_connect_sends_the_admin_to_monokulo_for_that_shop(): void {
		$answer = $this->module()->startConnect(1, self::ADMIN);

		$this->assertStringStartsWith(self::$monokulo->url . '/plugins/connect/prestashop?', $answer['redirect']);
		parse_str((string)parse_url($answer['redirect'], PHP_URL_QUERY), $query);
		$nonce = $this->rows('SELECT * FROM `' . _DB_PREFIX_ . 'monokulo_connect_nonce`')[0];
		$this->assertSame([
			'site_url'   => 'http://localhost:8093/',
			'return_url' => 'http://localhost:8093/module/monokulo/connect',
			'nonce'      => $nonce['nonce'],
			'name'       => 'PrestaShop',
		], $query);
		$this->assertSame(self::HOST, $nonce['host']);
		$this->assertSame('1', $nonce['id_shop']);
		$this->assertSame(self::ADMIN, $nonce['admin_return']);
		$this->assertEqualsWithDelta(time() + 600, (int)$nonce['expires_at'], 5);
	}

	/** The second shop returns to its own host, which Monokulo requires. */
	public function test_connect_for_a_second_shop_returns_to_its_host(): void {
		$shopId = $this->addSecondShop();

		parse_str((string)parse_url($this->module()->startConnect($shopId, self::ADMIN)['redirect'], PHP_URL_QUERY), $query);

		$this->assertSame('http://127.0.0.1:8093/', $query['site_url']);
		$this->assertSame('http://127.0.0.1:8093/module/monokulo/connect', $query['return_url']);
		$this->assertSame(self::SECOND_HOST, $this->rows('SELECT `host` FROM `' . _DB_PREFIX_ . 'monokulo_connect_nonce`')[0]['host']);
	}

	/** A shop with https on is connected by its https address. */
	public function test_an_https_shop_connects_by_its_https_address(): void {
		$this->setting('PS_SSL_ENABLED', '1');
		\Context::getContext()->link = new \Link(); // as the next request's would be

		parse_str((string)parse_url($this->module()->startConnect(1, self::ADMIN)['redirect'], PHP_URL_QUERY), $query);

		$this->assertSame('https://localhost:8093/', $query['site_url']);
		$this->assertSame('https://localhost:8093/module/monokulo/connect', $query['return_url']);
	}

	public function test_connect_needs_an_address_and_a_real_shop(): void {
		$this->assertSame(['error' => 'address'], $this->module()->startConnect(99, self::ADMIN));

		$this->setting(\Monokulo::ADDRESS, '');
		$this->assertSame(['error' => 'address'], $this->module()->startConnect(1, self::ADMIN));
		$this->assertSame([], $this->rows('SELECT * FROM `' . _DB_PREFIX_ . 'monokulo_connect_nonce`'));
	}

	/** Old attempts are cleared out as new ones start. */
	public function test_expired_nonces_are_cleared(): void {
		$this->repository()->createNonce(1, self::HOST, 'x', time() - 3600);

		$this->module()->startConnect(1, self::ADMIN);

		$this->assertCount(1, $this->rows('SELECT * FROM `' . _DB_PREFIX_ . 'monokulo_connect_nonce`'));
	}
}
