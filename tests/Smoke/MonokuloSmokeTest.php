<?php
namespace Monokulo\PrestaShop\Tests\Smoke;

use Monokulo\PrestaShop\OrderRequest;
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
 *    through the API, is pending.
 *
 * It also checks the stand-in Monokulo's API bodies (Support\Api) against
 * this Monokulo's own description of its API (its `service-desc`).
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
		$connection = Shop::rows("SELECT * FROM ps_monokulo_connection WHERE host = 'localhost:8093'")[0];
		$entry = $this->api($connection['api'], $connection['secret_token']);
		$orders = $this->api(Url::resolve($connection['api'], $entry['_links']['mk:orders']['href']), $connection['secret_token']);
		$this->assertCount(1, $orders['orders']);
		$order = $this->api(Url::resolve($connection['api'], $orders['orders'][0]['_links']['self']['href']), $connection['secret_token']);
		$this->assertSame('pending', $order['status']);
		$this->assertSame(0, $order['confirmations']);
		$this->assertSame($checkout, Url::resolve($connection['api'], $order['_links']['mk:checkout']['href']));
		$this->assertSame('GBP', $order['currency']);
		$this->assertSame(Shop::rows('SELECT reference FROM ps_orders ORDER BY id_order DESC LIMIT 1')[0]['reference'], $order['merchant_order_id']);

		$this->assertTheStandInSpeaksThisMonokulosApi($connection, $entry, $order);
	}

	/**
	 * The stand-in's bodies have this Monokulo's shape: the entry offers the
	 * same form, which takes every field the module sends, and an order has
	 * every field the stand-in's has.
	 */
	private function assertTheStandInSpeaksThisMonokulosApi(array $connection, array $entry, array $order): void {
		$names = static fn(array $form): array => array_column($form['properties'], 'name');
		$form = $entry['_templates']['create-order'];
		$standIn = Api::entry()['_templates']['create-order'];
		$this->assertSame([$standIn['method'], $standIn['target'], $standIn['contentType']], [$form['method'], $form['target'], $form['contentType']]);
		$this->assertEqualsCanonicalizing($names($standIn), $names($form));
		$sent = OrderRequest::body(1, 1, 'XKBKNABJK', 12.5, 'EUR', 2, ['Mug'], 'Order XKBKNABJK', ' + %d more');
		$this->assertSame([], array_diff(array_keys($sent), $names($form)), 'every field the module sends is one the form takes');

		$this->assertSame('/api/v1', $entry['_links']['self']['href']);
		$description = $this->api(Url::resolve($connection['api'], $entry['_links']['service-desc']['href']), null);
		$schema = $description['components']['schemas'];
		$this->assertEqualsCanonicalizing(array_keys(Api::order('order_x')), array_keys($order), 'the stand-in order has the real one\'s fields');
		$this->assertSame([], array_diff($schema['Order']['required'], array_keys(Api::order('order_x'))));
		$this->assertSame([], array_diff(array_keys($sent), array_keys($schema['OrdersInput']['properties'])));
		$this->assertSame([], array_diff($schema['OrdersInput']['required'] ?? [], array_keys($sent)));
		$this->assertMatchesRegularExpression('/' . $schema['Amount']['pattern'] . '/', $sent['amount']);
		foreach (array_keys(Api::order('order_x')['_links']) as $rel) {
			$this->assertArrayHasKey($rel, $order['_links'], $rel);
		}
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
		$connection = Shop::rows("SELECT * FROM ps_monokulo_connection WHERE host = 'localhost:8093'")[0];
		$this->assertSame($this->monokulo . '/api/v1', $connection['api']);
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
