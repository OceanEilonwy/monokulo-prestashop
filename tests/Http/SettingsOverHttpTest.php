<?php
namespace Monokulo\PrestaShop\Tests\Http;

use Monokulo\PrestaShop\Tests\Support\Api;
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
		Shop::exec("INSERT INTO ps_monokulo_connection SET host = 'localhost:8093', api = 'https://pay.example.com/api/v1', public_key = 'pk_test', secret_token = 'sk', webhook_secret = 'wh', connected_at = 1760000000");

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
		$this->assertStringNotContainsString('.onion', $text, 'connected before 0.3.0, or to a Monokulo with no onion address');
		$this->assertStringContainsString('No test webhook received yet.', $text);
		$this->assertStringContainsString('Test connection', $text);
	}

	public function test_over_http_the_page_shows_the_last_test_webhook_received(): void {
		Shop::exec("INSERT INTO ps_monokulo_connection SET host = 'localhost:8093', api = 'https://pay.example.com/api/v1', public_key = 'pk_test', secret_token = 'sk', webhook_secret = 'wh', connected_at = 1760000000, last_test_at = " . time() . ", last_test_event_id = 'evt_0000000000000012'");

		$this->assertMatchesRegularExpression('/Last test webhook received: \S.* \(evt_0000000000000012\)/', Shop::settingsPage($this->browser)->text());
	}

	/** Not connected: nothing to test. */
	public function test_over_http_no_test_button_before_connecting(): void {
		$page = Shop::settingsPage($this->browser);

		$this->assertStringNotContainsString('Test connection', $page->text());
		$this->assertStringNotContainsString('name="monokulo_test"', $page->body);
	}

	/**
	 * "Test connection" follows the entry's send-test-webhook form and says
	 * how it went, in the back office's own notices: delivered, not
	 * delivered (with when Monokulo tries again), or too many tests.
	 */
	public function test_over_http_test_connection_says_how_it_went(): void {
		Shop::exec("INSERT INTO ps_monokulo_connection SET host = 'localhost:8093', api = " . Shop::quote($this->monokulo->url . '/api/v1') . ", public_key = 'pk_test', secret_token = 'sk_http', webhook_secret = 'wh', connected_at = 1760000000");
		$this->monokulo->entry(Api::entry());
		$this->monokulo->respond('POST', '/api/v1/webhook-tests', 200, Api::testDelivered());

		$page = Shop::settingsPage($this->browser);
		$tested = $this->browser->submitWith($page, 'monokulo_test', ['submitMonokuloTest' => '1']);

		$this->assertSame(200, $tested->status);
		$this->assertStringContainsString('Monokulo sent this shop a test webhook, and the shop took it: HTTP 200 in 12 ms (' . Api::testDelivered()['event_id'] . ').', $tested->text());
		$this->assertMatchesRegularExpression('/class="[^"]*alert-success[^"]*"/', $tested->body, 'a confirmation');
		$request = $this->monokulo->requests()[1];
		$this->assertSame('POST /api/v1/webhook-tests', $request['method'] . ' ' . $request['path']);
		$this->assertSame('{}', $request['body']);
		$this->assertSame('Bearer sk_http', $request['headers']['authorization']);

		$this->monokulo->respond('POST', '/api/v1/webhook-tests', 200, Api::testNotDelivered());
		$failed = $this->browser->submitWith(Shop::settingsPage($this->browser), 'monokulo_test', ['submitMonokuloTest' => '1']);
		$this->assertStringContainsString('Monokulo sent this shop a test webhook, but the shop did not take it: answered 500. Monokulo tries again at', $failed->text());
		$this->assertMatchesRegularExpression('/class="[^"]*alert-danger[^"]*"/', $failed->body, 'an error');

		$limited = Api::recordedProblem('post', '/api/v1/webhook-tests', 'rate-limited');
		$this->monokulo->respond('POST', '/api/v1/webhook-tests', 429, $limited, headers: ['Content-Type' => 'application/problem+json', 'Retry-After' => '120']);
		$waited = $this->browser->submitWith(Shop::settingsPage($this->browser), 'monokulo_test', ['submitMonokuloTest' => '1']);
		$this->assertStringContainsString('Monokulo sends at most 6 test webhooks a minute for a store. Wait 120 seconds, then test again.', $waited->text());
		$this->assertMatchesRegularExpression('/class="[^"]*alert-warning[^"]*"/', $waited->body, 'a warning');

		$missing = Api::recordedProblem('post', '/api/v1/webhook-tests', 'not-found');
		$this->monokulo->respond('POST', '/api/v1/webhook-tests', 404, $missing, headers: ['Content-Type' => 'application/problem+json']);
		$notFound = $this->browser->submitWith(Shop::settingsPage($this->browser), 'monokulo_test', ['submitMonokuloTest' => '1']);
		$this->assertStringContainsString('Monokulo has no webhook for this shop to test. Connect the shop again.', $notFound->text());
	}

	/** Connected to a Monokulo with an onion address: Tor customers pay there. */
	public function test_over_http_the_page_shows_monokulos_onion_address(): void {
		Shop::exec("INSERT INTO ps_monokulo_connection SET host = 'localhost:8093', api = 'https://pay.example.com/api/v1', onion_api = 'http://abcdef.onion/api/v1', public_key = 'pk_test', secret_token = 'sk', webhook_secret = 'wh', connected_at = 1760000000");

		$text = Shop::settingsPage($this->browser)->text();

		$this->assertStringContainsString('Customers on the shop\'s .onion address pay on Monokulo\'s: http://abcdef.onion', $text);
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
		$this->assertStringContainsString('too many connect attempts from this shop. Wait a minute, then connect again.', Shop::settingsPage($this->browser, ['monokulo_connect_error' => 'busy'])->text());
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

		$this->assertStringContainsString('Enter an http or https address, such as https://www.monokulo.com.', $saved->text());
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
		$this->monokulo->respond('POST', '/plugins/connect/prestashop/finish', 200, ['public_key' => 'pk_http', 'secret_token' => 'sk_http', 'api' => '/api/v1', 'webhook_signing_secret' => 'whsec_http']);
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
