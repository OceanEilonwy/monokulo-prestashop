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
 * 4. a guest buys something with Monero: the shop places the order,
 *    makes the Monokulo order with the API entry's `create-order` form and
 *    sends them to the order's checkout, which loads; the order, read back
 *    through the API, is pending, and the merchant's link to it (its
 *    `mk:dashboard-order`) opens it on the dashboard;
 * 5. a second guest's order is one request, from the entry the shop kept,
 *    and once the kept entry has expired the shop checks it with its ETag.
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
		$checkout = $this->buyWithMonero();

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

		// The merchant's link, kept with the order, opens it on their dashboard.
		$dashboard = Shop::rows('SELECT dashboard FROM ps_monokulo_order ORDER BY seq DESC LIMIT 1')[0]['dashboard'];
		$this->assertSame(Url::resolve($connection['api'], $order['_links']['mk:dashboard-order']['href']), $dashboard);
		$merchantPage = $this->browser->get($dashboard);
		$this->assertSame(200, $merchantPage->status);
		$this->assertStringContainsString($order['merchant_order_id'], $merchantPage->text());

		$this->assertItAnswersAsTheRecordingsSay($connection, $entry, $order);
		$this->assertTheKeptEntryMakesAnOrderOneRequest();
		$this->assertAnUnknownKeyIsRefusedAtTheEntry($connection);
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
