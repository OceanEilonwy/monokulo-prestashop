<?php
namespace Monokulo\PrestaShop\Tests\Http;

use Monokulo\PrestaShop\Tests\Support\Browser;
use Monokulo\PrestaShop\Tests\Support\Shop;
use Monokulo\PrestaShop\Tests\Support\Vectors;
use PHPUnit\Framework\TestCase;

/**
 * Webhooks as Monokulo delivers them: real HTTP posts to the shop's webhook
 * address, through Apache and PrestaShop's front controller, with
 * Monokulo's published bodies byte for byte.
 *
 * The shop's clock is today's, not the vectors' `sent_at`, so each body is
 * signed again now with the vectors' secret, exactly as Monokulo signs
 * (`t=<now>,v1=HMAC-SHA256(secret, "<now>.<body>")`). The published
 * signatures themselves are checked at `sent_at` by the unit and front
 * suites; here, being old, they must be refused.
 */
final class WebhookOverHttpTest extends TestCase {
	private const WEBHOOK = Shop::URL . 'module/monokulo/webhook';

	private int $orderId;
	private int $placed;

	protected function setUp(): void {
		Shop::exec("DELETE FROM ps_monokulo_connection");
		Shop::exec("DELETE FROM ps_monokulo_order");
		Shop::exec("DELETE FROM ps_monokulo_event");
		Shop::exec("INSERT INTO ps_monokulo_connection SET host = 'localhost:8093', api = 'https://pay.example.com/api/v1', public_key = 'pk_test', secret_token = 'sk_test', webhook_secret = " . Shop::quote(Vectors::secret()) . ", connected_at = 1");
		$this->orderId = Shop::placeOrder();
		$this->placed = $this->orderStatus();
		Shop::exec("INSERT INTO ps_monokulo_order SET monokulo_order_id = " . Shop::quote(Vectors::orderId()) . ", id_order = " . $this->orderId . ", id_shop = 1, host = 'localhost:8093', amount = '14.28', currency = 'GBP', created_at = 1");
	}

	protected function tearDown(): void {
		Shop::exec("DELETE FROM ps_monokulo_connection");
		Shop::exec("DELETE FROM ps_monokulo_order");
		Shop::exec("DELETE FROM ps_monokulo_event");
	}

	private function post(string $body, array $headers, string $url = self::WEBHOOK): int {
		return (new Browser())->post($url, $body, $headers + ['Content-Type' => 'application/json'])->status;
	}

	/** Monokulo's delivery `name`, signed now. */
	private function deliver(string $name, array $extra = []): int {
		$delivery = Vectors::delivery($name);
		$t = time();
		$headers = ['X-Monokulo-Signature' => 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $delivery['body'], Vectors::secret())] + $delivery['headers'];

		return $this->post($delivery['body'], $extra + $headers);
	}

	private function orderStatus(): int {
		return (int)Shop::rows("SELECT current_state FROM ps_orders WHERE id_order = " . $this->orderId)[0]['current_state'];
	}

	/** @return list<string> */
	private function notes(): array {
		return array_column(Shop::rows("SELECT cm.message FROM ps_customer_message cm JOIN ps_customer_thread ct ON ct.id_customer_thread = cm.id_customer_thread WHERE ct.id_order = " . $this->orderId . " AND cm.private = 1 ORDER BY cm.id_customer_message"), 'message');
	}

	public function test_over_http_paid_marks_the_order_paid(): void {
		$this->assertSame(200, $this->deliver('order.paid'));

		$this->assertSame((int)Shop::setting('PS_OS_PAYMENT'), $this->orderStatus());
		$this->assertNotEmpty(Shop::rows("SELECT * FROM ps_order_payment p JOIN ps_orders o ON o.reference = p.order_reference WHERE o.id_order = " . $this->orderId), 'the payment is recorded');
		$this->assertStringContainsString('Monokulo: paid in full.', implode("\n", $this->notes()));
	}

	/** Every published event, in order, over HTTP: each accepted, ending paid, with a note for each about the order. */
	public function test_over_http_every_published_delivery_is_accepted(): void {
		foreach (array_keys(Vectors::deliveries()) as $name) {
			$this->assertSame(200, $this->deliver($name), $name);
		}
		$this->assertSame((int)Shop::setting('PS_OS_PAYMENT'), $this->orderStatus());
		$this->assertCount(count(Vectors::orderDeliveries()), $this->notes());
	}

	/** A test webhook over HTTP: 200, kept as the last test, and no order changes. */
	public function test_over_http_a_test_webhook_is_kept_and_changes_no_order(): void {
		$this->assertSame(200, $this->deliver('webhook.test'));

		$connection = Shop::rows("SELECT last_test_at, last_test_event_id FROM ps_monokulo_connection WHERE host = 'localhost:8093'")[0];
		$this->assertSame(Vectors::delivery('webhook.test')['headers']['X-Monokulo-Event-Id'], $connection['last_test_event_id']);
		$this->assertEqualsWithDelta(time(), (int)$connection['last_test_at'], 30);
		$this->assertSame($this->placed, $this->orderStatus());
		$this->assertSame([], $this->notes());
	}

	public function test_over_http_a_repeat_changes_nothing(): void {
		$this->assertSame(200, $this->deliver('order.expired'));
		$this->assertSame(200, $this->deliver('order.expired'));

		$this->assertSame((int)Shop::setting('PS_OS_CANCELED'), $this->orderStatus());
		$this->assertCount(1, $this->notes());
	}

	/** As published, the signatures are from `sent_at`: far more than five minutes ago. */
	public function test_over_http_a_replayed_published_delivery_is_refused(): void {
		$delivery = Vectors::delivery('order.paid');

		$this->assertSame(401, $this->post($delivery['body'], $delivery['headers']));
		$this->assertSame($this->placed, $this->orderStatus());
	}

	public function test_over_http_every_published_forgery_is_refused(): void {
		foreach (Vectors::refused() as $why => $refuse) {
			$this->assertSame(401, $this->post($refuse['body'], $refuse['headers']), $why);
		}
		$this->assertSame(401, $this->post(Vectors::delivery('order.paid')['body'], []), 'no signature at all');
		$this->assertSame($this->placed, $this->orderStatus());
		$this->assertSame([], $this->notes());
	}

	/** A second shop, on its own host, has no connection: nothing sent to its address is trusted. */
	public function test_over_http_the_host_decides_which_connection_checks_it(): void {
		$multishop = Shop::setting('PS_MULTISHOP_FEATURE_ACTIVE');
		Shop::exec("INSERT INTO ps_shop SET id_shop_group = 1, name = 'Second shop', color = '', id_category = 2, theme_name = 'classic', active = 1, deleted = 0");
		$shopId = Shop::lastId();
		Shop::exec("INSERT INTO ps_shop_url SET id_shop = $shopId, domain = '127.0.0.1:8093', domain_ssl = '127.0.0.1:8093', physical_uri = '/', virtual_uri = '', main = 1, active = 1");
		Shop::exec("INSERT INTO ps_module_shop (id_module, id_shop, enable_device) SELECT id_module, $shopId, enable_device FROM ps_module_shop WHERE id_shop = 1");
		Shop::configure('PS_MULTISHOP_FEATURE_ACTIVE', '1');

		try {
			$delivery = Vectors::delivery('order.paid');
			$t = time();
			$signature = 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $delivery['body'], Vectors::secret());

			$this->assertSame(401, $this->post($delivery['body'], ['X-Monokulo-Signature' => $signature], 'http://127.0.0.1:8093/module/monokulo/webhook'));
			$this->assertSame($this->placed, $this->orderStatus());
		} finally {
			Shop::configure('PS_MULTISHOP_FEATURE_ACTIVE', (string)$multishop);
			Shop::exec("DELETE FROM ps_module_shop WHERE id_shop = $shopId");
			Shop::exec("DELETE FROM ps_shop_url WHERE id_shop = $shopId");
			Shop::exec("DELETE FROM ps_shop WHERE id_shop = $shopId");
		}
	}

	/** The shop joins the webhook's trace, so its log lines match Monokulo's. */
	public function test_over_http_the_log_names_the_webhooks_trace(): void {
		$trace = bin2hex(random_bytes(16));

		$this->assertSame(200, $this->deliver('order.unconfirmed', ['traceparent' => '00-' . $trace . '-00f067aa0ba902b7-01']));

		$this->assertStringContainsString('applied to order ' . $this->orderId . '. [trace ' . $trace . ']', (string)file_get_contents('/var/www/html/var/logs/monokulo.log'));
	}

	/** Anything but a signed POST is refused too. */
	public function test_over_http_a_browser_visiting_the_address_is_refused(): void {
		$this->assertSame(401, (new Browser())->get(self::WEBHOOK)->status);
	}
}
