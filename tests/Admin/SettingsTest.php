<?php
namespace Monokulo\PrestaShop\Tests\Admin;

use Monokulo\PrestaShop\Tests\Support\Api;
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
		$this->assertSame('Enter an http or https address, such as https://www.monokulo.com.', $errors['address']);
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
			'return_url' => $query['return_url'],
			'nonce'      => $nonce['nonce'],
			'name'       => 'PrestaShop',
		], $query);
		$this->assertMatchesRegularExpression('#\Ahttp://localhost:8093/([a-z]{2}/)?module/monokulo/connect\z#', $query['return_url'], 'back to the shop itself');
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
		$this->assertMatchesRegularExpression('#\Ahttp://127\.0\.0\.1:8093/([a-z]{2}/)?module/monokulo/connect\z#', $query['return_url']);
		$this->assertSame(self::SECOND_HOST, $this->rows('SELECT `host` FROM `' . _DB_PREFIX_ . 'monokulo_connect_nonce`')[0]['host']);
	}

	/** A shop with https on is connected by its https address. */
	public function test_an_https_shop_connects_by_its_https_address(): void {
		$this->setting('PS_SSL_ENABLED', '1');
		$this->setting('PS_SSL_ENABLED_EVERYWHERE', '1'); // the back office turns both on
		\Context::getContext()->link = new \Link(); // as the next request's would be

		parse_str((string)parse_url($this->module()->startConnect(1, self::ADMIN)['redirect'], PHP_URL_QUERY), $query);

		$this->assertSame('https://localhost:8093/', $query['site_url']);
		$this->assertMatchesRegularExpression('#\Ahttps://localhost:8093/([a-z]{2}/)?module/monokulo/connect\z#', $query['return_url']);
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

	/** "Test connection" sends the shop's own connection's test, and says how it went. */
	public function test_test_connection_follows_the_form_for_that_shop(): void {
		$this->connect();
		self::$monokulo->entry(Api::entry());
		self::$monokulo->respond('POST', '/api/v1/webhook-tests', 200, Api::testDelivered());
		$before = $this->logSize();

		$outcome = $this->module()->testConnection(1);

		$this->assertSame(['confirmation', 'test_delivered'], [$outcome->notice, $outcome->key]);
		$this->assertSame('Bearer sk_test', self::$monokulo->requests()[1]['headers']['authorization']);
		$this->assertStringContainsString('Test connection for localhost:8093: test_delivered.', $this->logSince($before));
	}

	/** Not delivered: the log says what the shop answered. */
	public function test_a_test_not_delivered_is_logged(): void {
		$this->connect();
		self::$monokulo->entry(Api::entry());
		self::$monokulo->respond('POST', '/api/v1/webhook-tests', 200, Api::testNotDelivered());
		$before = $this->logSize();

		$this->assertSame('test_failed_retry', $this->module()->testConnection(1)->key);
		$this->assertStringContainsString('was not delivered to the shop: answered 500', $this->logSince($before));
	}

	public function test_a_shop_that_is_not_connected_has_nothing_to_test(): void {
		$this->assertSame('test_not_found', $this->module()->testConnection(1)->key);
		$this->assertSame('test_not_found', $this->module()->testConnection(99)->key);
		$this->assertSame([], self::$monokulo->requests());
	}
}
