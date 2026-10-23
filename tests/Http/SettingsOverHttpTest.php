<?php
namespace Monokulo\PrestaShop\Tests\Http;

use Monokulo\PrestaShop\Plugin;
use Monokulo\PrestaShop\Tests\Support\Browser;
use Monokulo\PrestaShop\Tests\Support\FakeMonokulo;
use Monokulo\PrestaShop\Tests\Support\Shop;
use PHPUnit\Framework\TestCase;

/**
 * The module's settings page as a merchant uses it, through Apache and the
 * back office: what it shows, saving it, and the connect flow's two
 * redirects (the Connect button to Monokulo, and Monokulo's return to the
 * shop, which redeems the token and sends the browser back). A stand-in
 * Monokulo plays Monokulo's part.
 */
final class SettingsOverHttpTest extends TestCase {
	private const KEYS = ['MONOKULO_ADDRESS', 'MONOKULO_FORWARD_ERRORS', 'MONOKULO_OS_PENDING', 'MONOKULO_OS_WAITING', 'MONOKULO_OS_PARTIAL', 'MONOKULO_OS_PAID', 'MONOKULO_OS_EXPIRED'];

	private FakeMonokulo $monokulo;
	private Browser $browser;

	/** @var array<string, string|null> */
	private array $saved = [];

	protected function setUp(): void {
		foreach (self::KEYS as $key) {
			$this->saved[$key] = Shop::setting($key);
		}
		$this->monokulo = new FakeMonokulo();
		Shop::configure('MONOKULO_ADDRESS', $this->monokulo->url);
		Shop::exec("DELETE FROM ps_monokulo_connection");
		$this->browser = Shop::admin();
	}

	protected function tearDown(): void {
		$this->monokulo->stop();
		foreach ($this->saved as $key => $value) {
			Shop::configure($key, (string)$value);
		}
		Shop::exec("DELETE FROM ps_monokulo_connection");
	}

	public function test_over_http_the_page_shows_each_shop_and_its_connection(): void {
		Shop::exec("INSERT INTO ps_monokulo_connection SET host = 'localhost:8093', endpoint = 'https://pay.example.com', public_key = 'pk_test', secret_token = 'sk', webhook_secret = 'wh', connection_version = " . Shop::quote(Plugin::CONNECTION_VERSION) . ", connected_at = 1760000000");

		$page = Shop::settingsPage($this->browser);

		$text = $page->text();
		$this->assertStringContainsString('Monokulo address', $text);
		$this->assertStringContainsString('value="' . $this->monokulo->url . '"', $page->body);
		$this->assertStringContainsString('Connected as pk_test since', $text);
		$this->assertStringContainsString('http://localhost:8093/', $text);
		$this->assertMatchesRegularExpression('/href="[^"]*configure(\/|=)monokulo[^"]*monokulo_connect=1"/', $page->body);
		$this->assertStringContainsString('Connect again', $text);
		foreach (['Order placed', 'Payment seen', 'Part paid', 'Paid', 'Expired', 'Send errors to Monokulo', 'Stores'] as $label) {
			$this->assertStringContainsString($label, $text);
		}
		$this->assertMatchesRegularExpression('/name="MONOKULO_OS_PAID".*?<option value="' . Shop::setting('PS_OS_PAYMENT') . '"\s+selected/s', $page->body);
	}

	/** Connected by an older version of this module: the page says to connect again. */
	public function test_over_http_an_old_connection_asks_to_connect_again(): void {
		Shop::exec("INSERT INTO ps_monokulo_connection SET host = 'localhost:8093', endpoint = 'https://pay.example.com', public_key = 'pk_old', secret_token = 'sk', webhook_secret = 'wh', connection_version = '0', connected_at = 1760000000");

		$this->assertStringContainsString('Connect again: this shop was connected by an older version of this module', Shop::settingsPage($this->browser)->text());
	}

	public function test_over_http_no_connect_button_before_there_is_an_address(): void {
		Shop::configure('MONOKULO_ADDRESS', '');

		$page = Shop::settingsPage($this->browser);

		$this->assertStringContainsString('Save your Monokulo address first.', $page->text());
		$this->assertStringNotContainsString('monokulo_connect=', $page->body);
	}

	public function test_over_http_the_page_says_how_connecting_went(): void {
		$this->assertStringContainsString('Your store is connected. Monero is now offered at checkout.', Shop::settingsPage($this->browser, ['monokulo_connected' => '1'])->text());
		$this->assertStringContainsString('has not set its public address', Shop::settingsPage($this->browser, ['monokulo_connect_error' => 'unavailable'])->text());
		$this->assertStringNotContainsString('script>alert', Shop::settingsPage($this->browser, ['monokulo_connect_error' => '<script>alert(1)</script>'])->body);
	}

	public function test_over_http_saving_keeps_the_settings(): void {
		$page = Shop::settingsPage($this->browser);
		$bankWire = (string)Shop::setting('PS_OS_BANKWIRE');

		$saved = $this->browser->submitWith($page, 'MONOKULO_ADDRESS', [
			'MONOKULO_ADDRESS'        => '  https://pay.example.com/ ',
			'MONOKULO_FORWARD_ERRORS' => '1',
			'MONOKULO_OS_PAID'        => $bankWire,
			'submitMonokulo'          => '1',
		]);

		$this->assertStringContainsString('The Monokulo settings are saved.', $saved->text());
		$this->assertSame('https://pay.example.com', Shop::setting('MONOKULO_ADDRESS'));
		$this->assertSame('1', Shop::setting('MONOKULO_FORWARD_ERRORS'));
		$this->assertSame($bankWire, Shop::setting('MONOKULO_OS_PAID'));
	}

	public function test_over_http_saving_refuses_a_bad_address(): void {
		$page = Shop::settingsPage($this->browser);

		$saved = $this->browser->submitWith($page, 'MONOKULO_ADDRESS', ['MONOKULO_ADDRESS' => 'pay.example.com', 'MONOKULO_FORWARD_ERRORS' => '1', 'submitMonokulo' => '1']);

		$this->assertStringContainsString('Enter an http or https address, such as https://pay.example.com.', $saved->text());
		$this->assertSame($this->monokulo->url, Shop::setting('MONOKULO_ADDRESS'), 'nothing saved');
		$this->assertSame((string)$this->saved['MONOKULO_FORWARD_ERRORS'], (string)Shop::setting('MONOKULO_FORWARD_ERRORS'));
	}

	public function test_over_http_connect_goes_to_monokulo_and_back(): void {
		$page = Shop::settingsPage($this->browser);
		$this->assertMatchesRegularExpression('/href="([^"]*monokulo_connect=1)"/', $page->body);
		preg_match('/href="([^"]*monokulo_connect=1)"/', $page->body, $m);

		$toMonokulo = $this->browser->get(html_entity_decode($m[1]));
		$toMonokulo = $toMonokulo->location() !== null && !str_starts_with((string)$toMonokulo->location(), $this->monokulo->url) ? $this->browser->get((string)$toMonokulo->location()) : $toMonokulo;
		$location = (string)$toMonokulo->location();
		$this->assertStringStartsWith($this->monokulo->url . '/plugins/connect/prestashop?', $location);
		parse_str((string)parse_url($location, PHP_URL_QUERY), $query);
		$this->assertSame('PrestaShop', $query['name']);
		$this->assertSame(Shop::URL, $query['site_url']);
		$this->assertMatchesRegularExpression('#\A' . preg_quote(Shop::URL, '#') . '([a-z]{2}/)?module/monokulo/connect\z#', $query['return_url']);

		// Monokulo sends the browser back to return_url with its token.
		$this->monokulo->respond('POST', '/plugins/connect/prestashop/finish', 200, ['public_key' => 'pk_http', 'secret_token' => 'sk_http', 'endpoint' => $this->monokulo->url, 'webhook_signing_secret' => 'whsec_http']);
		$back = $this->browser->get($query['return_url'] . '?token=tok_http&nonce=' . $query['nonce']);

		$this->assertSame(302, $back->status);
		$this->assertStringContainsString('monokulo_connected=1', (string)$back->location());
		$this->assertSame('pk_http', Shop::rows("SELECT public_key FROM ps_monokulo_connection WHERE host = 'localhost:8093'")[0]['public_key']);
		$this->assertSame(Shop::URL . 'index.php?fc=module&module=monokulo&controller=webhook', json_decode($this->monokulo->requests()[0]['body'], true)['webhook_url']);
		$this->assertStringContainsString('Your store is connected', $this->browser->follow($back)->text());

		// The same return again: the nonce is spent.
		$again = $this->browser->get($query['return_url'] . '?token=tok_http&nonce=' . $query['nonce']);
		$this->assertSame(400, $again->status);
		$this->assertStringContainsString('already been used or has expired', $again->body);
	}
}
