<?php
namespace Monokulo\PrestaShop\Tests\Smoke;

use Monokulo\PrestaShop\Tests\Support\Api;
use Monokulo\PrestaShop\Tests\Support\Browser;
use Monokulo\PrestaShop\Tests\Support\Page;
use Monokulo\PrestaShop\Tests\Support\Shop;
use Monokulo\PrestaShop\Tests\Support\Storefront;
use Monokulo\PrestaShop\Url;
use PHPUnit\Framework\TestCase;

/**
 * The module against a real Monokulo (MONOKULO_URL, a fresh instance
 * started with docker/monokulo-smoke.toml), as a merchant and a customer
 * use it, over HTTP:
 *
 * 1. the merchant signs up to Monokulo and adds a watch-only wallet;
 * 2. saves the Monokulo address in the module's settings and presses
 *    Connect;
 * 3. on Monokulo, sets up the store for the shop, picks the wallet, and
 *    goes back to PrestaShop, which redeems the token: connected;
 * 4. a guest buys something with Monero while Monokulo has no exchange rate
 *    (its rate provider, tests/Support/fake-rates.php, rate limits it):
 *    Monokulo's real `rates-unavailable`, so no Monokulo order, the guest
 *    is asked to try again shortly, and the merchant's log says why. The
 *    provider back, the guest tries again from the payment link: the shop
 *    places no second order,
 *    makes the Monokulo order with the API entry's `create-order` form and
 *    sends them to the order's checkout, which loads; the order, read back
 *    through the API, is pending, and its `mk:dashboard-order` (the
 *    merchant's link, which the shop takes from each of the order's
 *    webhooks, so keeps none) opens it on the dashboard;
 * 5. a second guest's order is one request, from the entry the shop kept,
 *    and once the kept entry has expired the shop checks it with its ETag;
 * 6. the connection, end to end: the entry's `send-test-webhook` form has
 *    Monokulo send the shop's webhook (the address the shop registered
 *    when it connected) a signed `webhook.test`, which it delivers (`2xx`);
 *    the shop's receiver kept that same event as the last test (it keeps
 *    only one whose signature checked out), no order changed, and the
 *    settings page says so. Then the admin presses "Test connection" on
 *    the real settings page, which does the same and says it was delivered.
 *    Two tests: well under Monokulo's 6 a minute for a store.
 *
 * This Monokulo has an onion address (docker/monokulo-smoke.toml): the
 * handshake gives the shop its onion entry, and each order its onion
 * checkout. It also checks that this Monokulo answers as the recordings
 * the other suites' stand-in answers with (Support\Api) say, and that a key
 * it doesn't know is refused at the entry.
 *
 * Nothing is paid. Monokulo's pages are filled in by their fields' names.
 */
final class MonokuloSmokeTest extends TestCase {
	/** A mainnet watch-only wallet: a fixed view key and its public spend key, for tests only. */
	private const VIEW_KEY = '0707070707070707070707070707070707070707070707070707070707070707';
	private const SPEND_PUBLIC_KEY = '8621f587cfc4d6f869720476565ecd0972451ff7b8dada3498c9d3c2ca54fc90';
	private const PASSWORD = 'correct horse battery staple';
	/** While this file is there, the rate provider's stand-in rate limits Monokulo (tests/Support/fake-rates.php). */
	private const RATES_DOWN = '/tmp/monokulo-smoke-rates-down';

	private string $monokulo;
	private Browser $browser;

	protected function setUp(): void {
		$url = getenv('MONOKULO_URL');
		if (!$url) {
			$this->markTestSkipped('Set MONOKULO_URL to a fresh Monokulo (scripts/smoke starts one).');
		}
		$this->monokulo = rtrim($url, '/');
		$this->browser = new Browser();
		Shop::exec("DELETE FROM ps_monokulo_connection");
	}

	public function test_connect_then_a_customer_is_sent_to_pay(): void {
		$this->signUpToMonokulo();
		$this->connectTheShop();
		$checkout = $this->buyWithNoExchangeRateThenAgain();

		// The Monokulo checkout loads.
		$page = $this->browser->get($checkout);
		$this->assertSame(200, $page->status, $page->text());

		// The order, found from the API's entry with the shop's key, is
		// waiting for its payment, and its checkout is where the customer went.
		$connection = $this->connection();
		$entry = $this->api($connection['api'], $connection['secret_token']);
		$orders = $this->api(Url::resolve($connection['api'], $entry['_links']['mk:orders']['href']), $connection['secret_token']);
		$this->assertCount(1, $orders['orders']);
		$order = $this->api(Url::resolve($connection['api'], $orders['orders'][0]['_links']['self']['href']), $connection['secret_token']);
		$this->assertSame('pending', $order['status']);
		$this->assertSame(0, $order['confirmations']);
		$this->assertSame($checkout, Url::resolve($connection['api'], $order['_links']['mk:checkout']['href']));
		$this->assertSame(Api::ONION . '/checkout/' . $order['order_id'], $order['_links']['mk:onion-checkout']['href']);
		$this->assertSame('GBP', $order['currency']);
		$this->assertSame(Shop::rows('SELECT reference FROM ps_orders ORDER BY id_order DESC LIMIT 1')[0]['reference'], $order['merchant_order_id']);

		// The merchant's link (which reaches the shop with each webhook) opens it on their dashboard.
		$merchantPage = $this->browser->get(Url::resolve($connection['api'], $order['_links']['mk:dashboard-order']['href']));
		$this->assertSame(200, $merchantPage->status);
		$this->assertStringContainsString($order['merchant_order_id'], $merchantPage->text());

		$this->assertItAnswersAsTheRecordingsSay($connection, $entry, $order);
		$this->assertTheKeptEntryMakesAnOrderOneRequest();
		$this->assertAnUnknownKeyIsRefusedAtTheEntry($connection);
		$this->assertATestWebhookReachesTheShop($connection, $entry);
		$this->assertTestConnectionSaysItWasDelivered();
	}

	/** @return array<string, mixed> every order's status, history, payments and notes: what a webhook could change */
	private function orderState(): array {
		return [
			Shop::rows('SELECT id_order, current_state FROM ps_orders ORDER BY id_order'),
			Shop::rows('SELECT COUNT(*) AS n FROM ps_order_history')[0]['n'],
			Shop::rows('SELECT COUNT(*) AS n FROM ps_order_payment')[0]['n'],
			Shop::rows('SELECT COUNT(*) AS n FROM ps_customer_message')[0]['n'],
			Shop::rows('SELECT monokulo_order_id, paid_at FROM ps_monokulo_order ORDER BY seq'),
			Shop::rows('SELECT COUNT(*) AS n FROM ps_monokulo_event')[0]['n'],
		];
	}

	/**
	 * The entry's send-test-webhook form, sent as the shop's key: Monokulo
	 * delivers a signed webhook.test to the shop's own webhook address, and
	 * the shop's receiver keeps it and changes no order.
	 */
	private function assertATestWebhookReachesTheShop(array $connection, array $entry): void {
		$before = $this->orderState();
		$form = $entry['_templates']['send-test-webhook'];
		$curl = curl_init(Url::resolve($connection['api'], $form['target']));
		curl_setopt_array($curl, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CUSTOMREQUEST  => $form['method'],
			CURLOPT_POSTFIELDS     => '{}',
			CURLOPT_TIMEOUT        => 30,
			CURLOPT_HTTPHEADER     => ['Content-Type: ' . $form['contentType'], 'Accept: application/prs.hal-forms+json', 'Authorization: Bearer ' . $connection['secret_token']],
		]);
		$body = (string)curl_exec($curl);
		$this->assertSame(200, curl_getinfo($curl, CURLINFO_RESPONSE_CODE), $body);
		$answer = json_decode($body, true);

		$this->assertTrue($answer['delivered'], 'delivered: ' . $body);
		$this->assertGreaterThanOrEqual(200, $answer['status']);
		$this->assertLessThan(300, $answer['status']);
		$this->assertArrayNotHasKey('error', $answer);
		$this->assertMatchesRegularExpression('/\Aevt_[0-9a-zA-Z_]+\z/', $answer['event_id']);
		$this->assertEqualsCanonicalizing(array_keys(Api::testDelivered()), array_keys($answer), 'the recording\'s shape');

		// Kept by the receiver, which keeps a test only once its signature checks out.
		$kept = $this->connection();
		$this->assertSame($answer['event_id'], $kept['last_test_event_id']);
		$this->assertEqualsWithDelta(time(), (int)$kept['last_test_at'], 60);
		$this->assertSame($before, $this->orderState(), 'no order changed');

		$page = Shop::settingsPage($this->browser); // signed in to the back office since connecting
		$this->assertMatchesRegularExpression('/Last test webhook received: \S.* \(' . preg_quote($answer['event_id'], '/') . '\)/', $page->text());
	}

	/** The admin presses "Test connection" on the real settings page: delivered, and the page shows that test. */
	private function assertTestConnectionSaysItWasDelivered(): void {
		$before = $this->orderState();
		$last = $this->connection()['last_test_event_id'];
		$admin = $this->browser; // signed in to the back office since connecting

		$tested = $admin->submitWith(Shop::settingsPage($admin), 'monokulo_test', ['submitMonokuloTest' => '1']);

		$this->assertSame(200, $tested->status);
		$this->assertMatchesRegularExpression('/Monokulo sent this shop a test webhook, and the shop took it: HTTP 2\d\d in \d+ ms \((evt_[^)]+)\)\./', $tested->text());
		preg_match('/the shop took it: HTTP 2\d\d in \d+ ms \((evt_[^)]+)\)\./', $tested->text(), $m);
		$this->assertNotSame($last, $m[1], 'a new test');
		$this->assertSame($m[1], $this->connection()['last_test_event_id']);
		$this->assertStringContainsString('(' . $m[1] . ')', $tested->text(), 'the page shows it as the last test received');
		$this->assertMatchesRegularExpression('/Last test webhook received: \S.* \(' . preg_quote($m[1], '/') . '\)/', $tested->text());
		$this->assertSame($before, $this->orderState(), 'no order changed');
	}

	/** @return array<string, string> the shop's connection row */
	private function connection(): array {
		return Shop::rows("SELECT * FROM ps_monokulo_connection WHERE host = 'localhost:8093'")[0];
	}

	/**
	 * The stand-in's bodies (recorded in the OpenAPI document at the pinned
	 * commit) have this Monokulo's shape: the same links and forms in the
	 * entry, the same fields and links on an order.
	 */
	private function assertItAnswersAsTheRecordingsSay(array $connection, array $entry, array $order): void {
		$recorded = Api::entry();
		$this->assertEqualsCanonicalizing(array_keys($recorded['_links']), array_keys($entry['_links']));
		$this->assertEqualsCanonicalizing(array_keys($recorded['_templates']), array_keys($entry['_templates']));
		foreach ($recorded['_templates'] as $name => $form) {
			$this->assertSame([$form['method'], $form['target'], $form['contentType']], [$entry['_templates'][$name]['method'], $entry['_templates'][$name]['target'], $entry['_templates'][$name]['contentType']], $name);
			$this->assertEqualsCanonicalizing(array_column($form['properties'], 'name'), array_column($entry['_templates'][$name]['properties'], 'name'), $name);
		}
		$this->assertSame(Api::ONION . '/api/v1', $entry['_links']['mk:onion-api']['href']);

		// The order as made, the read one's fields and links are the same.
		$made = Api::order($order['order_id']);
		$this->assertEqualsCanonicalizing(array_keys($made), array_keys($order));
		$this->assertEqualsCanonicalizing(array_keys($made['_links']), array_keys($order['_links']));

		$description = $this->api(Url::resolve($connection['api'], $entry['_links']['service-desc']['href']), null);
		$this->assertEqualsCanonicalizing(array_keys(Api::document()['paths']), array_keys($description['paths']), 'the same routes as the pinned document');
	}

	/**
	 * The shop kept the entry it read for its key: the next order is made
	 * with no request for it. Once it has expired, the shop asks with its
	 * ETag, and Monokulo's `304` keeps it another hour.
	 */
	private function assertTheKeptEntryMakesAnOrderOneRequest(): void {
		$kept = $this->connection();
		$this->assertNotSame('', $kept['entry_etag']);
		$this->assertEqualsWithDelta(time() + 3600, (int)$kept['entry_expires_at'], 120);
		$this->assertSame(Api::entry()['_templates']['create-order']['target'], json_decode($kept['entry'], true)['_templates']['create-order']['target']);

		$this->buyWithMonero();
		$this->assertSame($kept['entry_expires_at'], $this->connection()['entry_expires_at'], 'not read again');

		Shop::exec("UPDATE ps_monokulo_connection SET entry_expires_at = 0 WHERE host = 'localhost:8093'");
		$this->buyWithMonero();
		$again = $this->connection();
		$this->assertSame($kept['entry_etag'], $again['entry_etag']);
		$this->assertSame($kept['entry'], $again['entry']);
		$this->assertEqualsWithDelta(time() + 3600, (int)$again['entry_expires_at'], 120);
	}

	/** A key this Monokulo doesn't know is refused at the entry, as a problem. */
	private function assertAnUnknownKeyIsRefusedAtTheEntry(array $connection): void {
		$curl = curl_init($connection['api']);
		curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Authorization: Bearer sk_' . str_repeat('0', 64)]]);
		$body = (string)curl_exec($curl);
		$this->assertSame(401, curl_getinfo($curl, CURLINFO_RESPONSE_CODE), $body);
		$this->assertStringStartsWith('application/problem+json', (string)curl_getinfo($curl, CURLINFO_CONTENT_TYPE));
		$this->assertSame(Api::recordedProblem('get', '/api/v1', 'unauthorized')['type'], json_decode($body, true)['type']);
	}

	/** GET `url` from Monokulo's API, with `key` when given. */
	private function api(string $url, ?string $key): array {
		$curl = curl_init($url);
		curl_setopt_array($curl, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => array_merge(['Accept: application/prs.hal-forms+json'], $key !== null ? ['Authorization: Bearer ' . $key] : []),
		]);
		$body = (string)curl_exec($curl);
		$this->assertSame(200, curl_getinfo($curl, CURLINFO_RESPONSE_CODE), $url . ': ' . $body);

		return json_decode($body, true);
	}

	/** A fresh Monokulo asks for its operator first; that account is the merchant's too. */
	private function signUpToMonokulo(): void {
		$home = $this->browser->get($this->monokulo . '/');
		$email = 'merchant-' . bin2hex(random_bytes(4)) . '@example.test';
		if (str_ends_with((string)$home->location(), '/admin/setup')) {
			$page = $this->browser->get($this->monokulo . '/admin/setup');
			$done = $this->browser->submit($page, '/admin/setup', ['email' => $email, 'password' => self::PASSWORD, 'confirm_password' => self::PASSWORD]);
		} else {
			$page = $this->browser->get($this->monokulo . '/signup');
			$done = $this->browser->submit($page, '/signup', ['email' => $email, 'password' => self::PASSWORD, 'confirm_password' => self::PASSWORD]);
		}
		$this->assertContains($done->status, [302, 303], 'signed up: ' . $done->text());

		$form = $this->browser->get($this->monokulo . '/account/wallets/import?network=mainnet');
		$added = $this->browser->submit($form, '/account/wallets/import', [
			'name'             => 'Shop takings',
			'view_key_hex'     => self::VIEW_KEY,
			'spend_pubkey_hex' => self::SPEND_PUBLIC_KEY,
			'network'          => 'mainnet',
		]);
		$this->assertSame(303, $added->status, 'wallet added: ' . $added->text());
	}

	private function connectTheShop(): void {
		$admin = Shop::admin($this->browser);

		$settings = Shop::settingsPage($admin);
		$saved = $admin->submitWith($settings, 'MONOKULO_ADDRESS', ['MONOKULO_ADDRESS' => $this->monokulo, 'submitMonokulo' => '1']);
		$this->assertStringContainsString('The Monokulo settings are saved.', $saved->text());

		// Connect: off to Monokulo, signed in already, with no store for the shop yet: store setup.
		$settings = Shop::settingsPage($admin);
		$this->assertMatchesRegularExpression('/href="([^"]*monokulo_connect=1)"/', $settings->body);
		preg_match('/href="([^"]*monokulo_connect=1)"/', $settings->body, $m);
		$toMonokulo = $admin->get(html_entity_decode($m[1]));
		$this->assertStringStartsWith($this->monokulo . '/plugins/connect/prestashop?', (string)$toMonokulo->location(), $toMonokulo->status . ' ' . substr($toMonokulo->body, 0, 2000));

		$setup = $this->browser->follow($this->browser->get((string)$toMonokulo->location()));
		$this->assertStringContainsString('PrestaShop', $setup->text(), 'Monokulo names the plugin');
		$wallet = $this->browser->follow($this->browser->submit($setup, '/setup', ['store_name' => 'Smoke test shop']));

		$done = $this->browser->follow($this->browser->submit($wallet, '/setup/wallet/existing'));
		$this->assertStringContainsString('Back to PrestaShop', $done->text());

		// Back to PrestaShop: Monokulo returns to the shop with the token, the
		// shop redeems it and sends the browser to its settings page.
		$back = $this->browser->submit($done, '/plugins/connect/prestashop');
		$this->assertMatchesRegularExpression('#\A' . preg_quote(Shop::URL, '#') . '([a-z]{2}/)?module/monokulo/connect\?#', (string)$back->location());
		$return = $this->browser->get((string)$back->location());
		$this->assertStringContainsString('monokulo_connected=1', (string)$return->location(), $return->body);

		$page = $admin->follow($admin->get((string)$return->location()));
		$this->assertStringContainsString('Your store is connected', $page->text());
		$connection = $this->connection();
		$this->assertSame($this->monokulo . '/api/v1', $connection['api']);
		$this->assertSame(Api::ONION . '/api/v1', $connection['onion_api']);
		$this->assertStringContainsString('Customers on the shop\'s .onion address pay on Monokulo\'s: ' . Api::ONION, $page->text());
		$this->assertStringStartsWith('sk_', $connection['secret_token']);
		$this->assertStringStartsWith('pk_', $connection['public_key']);
		$this->assertNotSame('', $connection['webhook_secret']);
	}

	/**
	 * A guest checkout with Monero while Monokulo's rate provider rate
	 * limits it: no exchange rate, so no Monokulo order, and the guest is
	 * asked to try again. Once it answers again, the payment link makes
	 * the order (with the same request) and sends the guest to its
	 * checkout, which is returned.
	 */
	private function buyWithNoExchangeRateThenAgain(): string {
		$customer = new Browser();
		$payment = Storefront::toPayment($customer);
		$logs = (int)Shop::rows("SELECT COUNT(*) AS n FROM ps_log WHERE object_type = 'Monokulo'")[0]['n'];
		$made = $this->monokuloOrders();

		touch(self::RATES_DOWN);
		try {
			$answer = Storefront::payWithMonero($customer, $payment);
		} finally {
			unlink(self::RATES_DOWN);
		}
		$this->assertSame(200, $answer->status, $answer->text());
		$this->assertStringContainsString('Monero payments can\'t be started at this moment. Please try again shortly: your order is kept.', $answer->text());
		$this->assertSame($made, $this->monokuloOrders(), 'no Monokulo order');
		$logged = implode("\n", array_column(Shop::rows("SELECT message FROM ps_log WHERE object_type = 'Monokulo' ORDER BY id_log LIMIT 100 OFFSET $logs"), 'message'));
		$this->assertStringContainsString('HTTP 503', $logged);
		$this->assertStringContainsString('no exchange rate for this currency right now', $logged);
		$this->assertMatchesRegularExpression('/after \d+ seconds as Monokulo asked/', $logged, 'Monokulo passed on the provider\'s Retry-After');
		$this->assertMatchesRegularExpression('/id="monokulo-retry"[^>]*href="([^"]+)"/', $answer->body);
		preg_match('/id="monokulo-retry"[^>]*href="([^"]+)"/', $answer->body, $m);
		$orders = (int)Shop::rows('SELECT COUNT(*) AS n FROM ps_orders')[0]['n'];

		sleep(3); // the provider's Retry-After
		$again = $customer->get(html_entity_decode($m[1]));
		$this->assertSame(302, $again->status, $again->text());
		$this->assertMatchesRegularExpression('#\A' . preg_quote($this->monokulo, '#') . '/checkout/order_[0-9a-f-]{32,36}\z#', (string)$again->location());
		$this->assertSame($orders, (int)Shop::rows('SELECT COUNT(*) AS n FROM ps_orders')[0]['n'], 'the same shop order');
		$this->assertSame($made + 1, $this->monokuloOrders());

		return (string)$again->location();
	}

	private function monokuloOrders(): int {
		return (int)Shop::rows('SELECT COUNT(*) AS n FROM ps_monokulo_order')[0]['n'];
	}

	/** A guest checkout with Monero; returns the Monokulo checkout it was sent to. */
	private function buyWithMonero(): string {
		$customer = new Browser();
		$payment = Storefront::toPayment($customer);
		$this->assertStringContainsString('Pay with Monero', $payment->text());

		$answer = Storefront::payWithMonero($customer, $payment);
		$this->assertSame(302, $answer->status, $answer->text());
		$this->assertMatchesRegularExpression('#\A' . preg_quote($this->monokulo, '#') . '/checkout/order_[0-9a-f-]{32,36}\z#', (string)$answer->location());

		$order = Shop::rows("SELECT o.id_order, o.current_state FROM ps_monokulo_order m JOIN ps_orders o ON o.id_order = m.id_order ORDER BY m.seq DESC LIMIT 1")[0];
		$this->assertSame(Shop::setting('MONOKULO_OS_PENDING'), (string)$order['current_state'], 'placed, awaiting its Monero payment');

		return (string)$answer->location();
	}
}
