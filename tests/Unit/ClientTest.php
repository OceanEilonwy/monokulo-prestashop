<?php
namespace Monokulo\PrestaShop\Tests\Unit;

use Monokulo\PrestaShop\ApiError;
use Monokulo\PrestaShop\Client;
use Monokulo\PrestaShop\Connection;
use Monokulo\PrestaShop\Plugin;
use Monokulo\PrestaShop\Tests\Support\Api;
use Monokulo\PrestaShop\Tests\Support\FakeMonokulo;
use Monokulo\PrestaShop\Trace;
use Monokulo\PrestaShop\TransportError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The client against a stand-in Monokulo over real HTTP on this machine. */
final class ClientTest extends TestCase {
	private static FakeMonokulo $monokulo;

	public static function setUpBeforeClass(): void {
		self::$monokulo = new FakeMonokulo();
	}

	public static function tearDownAfterClass(): void {
		self::$monokulo->stop();
	}

	protected function setUp(): void {
		self::$monokulo->reset();
	}

	private function connection(): Connection {
		return new Connection('shop.example', self::$monokulo->url . '/api/v1', 'pk_test', 'sk_test', 'whsec_test', 1);
	}

	/** `api` is a path on the Monokulo the token was redeemed at. */
	public function test_finishing_connect_sends_the_token_webhook_and_name(): void {
		self::$monokulo->respond('POST', '/plugins/connect/prestashop/finish', 200, ['public_key' => 'pk_1', 'secret_token' => 'sk_1', 'api' => '/api/v1', 'webhook_signing_secret' => 'whsec_1']);
		$trace = new Trace();

		$credentials = (new Client($trace))->finishConnect(self::$monokulo->url . '/', 'tok', 'https://shop.example/module/monokulo/webhook');

		$this->assertSame(['public_key' => 'pk_1', 'secret_token' => 'sk_1', 'api' => self::$monokulo->url . '/api/v1', 'webhook_signing_secret' => 'whsec_1'], $credentials);
		$request = self::$monokulo->requests()[0];
		$this->assertSame(['token' => 'tok', 'webhook_url' => 'https://shop.example/module/monokulo/webhook', 'name' => 'PrestaShop'], json_decode($request['body'], true));
		$this->assertSame('prestashop/' . Plugin::VERSION, $request['headers']['monokulo-client']);
		$this->assertSame('application/json', $request['headers']['content-type']);
		$this->assertStringStartsWith('00-' . $trace->id() . '-', $request['headers']['traceparent']);
		$this->assertArrayNotHasKey('authorization', $request['headers']);
	}

	/** Without a webhook secret the shop could never trust a webhook, and without the API's entry it can't make orders: that's no connection. */
	public function test_finishing_without_every_credential_fails(): void {
		self::$monokulo->respond('POST', '/plugins/connect/prestashop/finish', 200, ['public_key' => 'pk_1', 'secret_token' => 'sk_1', 'api' => '/api/v1']);
		try {
			(new Client(new Trace()))->finishConnect(self::$monokulo->url, 'tok', 'https://shop.example/');
			$this->fail('no error');
		} catch (ApiError $e) {
			$this->assertStringContainsString('missing webhook_signing_secret', $e->detail);
		}

		// The old answer, before Monokulo's HTTP API.
		self::$monokulo->respond('POST', '/plugins/connect/prestashop/finish', 200, ['public_key' => 'pk_1', 'secret_token' => 'sk_1', 'endpoint' => 'https://pay.example.com', 'webhook_signing_secret' => 'whsec_1']);
		$this->expectException(ApiError::class);
		$this->expectExceptionMessage('missing api');
		(new Client(new Trace()))->finishConnect(self::$monokulo->url, 'tok', 'https://shop.example/');
	}

	public function test_a_monokulo_without_a_public_address_says_why(): void {
		self::$monokulo->respond('POST', '/plugins/connect/prestashop/finish', 503, ['error' => 'no public address yet']);

		try {
			(new Client(new Trace()))->finishConnect(self::$monokulo->url, 'tok', 'https://shop.example/');
			$this->fail('no error');
		} catch (ApiError $e) {
			$this->assertSame(503, $e->status);
			$this->assertSame('no public address yet', $e->detail);
		}
	}

	/**
	 * The client reads the entry with the store's key, fills in its
	 * `create-order` form, and hands back the order's checkout link,
	 * resolved against Monokulo's address.
	 */
	public function test_creating_an_order_follows_the_entrys_form(): void {
		self::$monokulo->api('order_1');

		$order = (new Client(new Trace()))->createOrder($this->connection(), ['amount' => '1', 'currency' => 'EUR']);

		$this->assertSame(['order_id' => 'order_1', 'checkout' => self::$monokulo->url . '/checkout/order_1'], $order);
		[$entry, $create] = self::$monokulo->requests();
		$this->assertSame(['GET', '/api/v1', ''], [$entry['method'], $entry['path'], $entry['body']]);
		$this->assertSame('Bearer sk_test', $entry['headers']['authorization']);
		$this->assertStringContainsString('application/prs.hal-forms+json', $entry['headers']['accept']);
		$this->assertSame(['POST', '/api/v1/orders'], [$create['method'], $create['path']]);
		$this->assertSame('Bearer sk_test', $create['headers']['authorization']);
		$this->assertSame('application/json', $create['headers']['content-type']);
		$this->assertSame('prestashop/' . Plugin::VERSION, $create['headers']['monokulo-client']);
		$this->assertSame(['amount' => '1', 'currency' => 'EUR'], json_decode($create['body'], true));
	}

	/** Where the form says to send it is where it goes: no address past the entry is the client's own. */
	public function test_the_form_decides_where_the_order_goes(): void {
		$entry = Api::entry();
		$entry['_templates']['create-order']['target'] = '/elsewhere/orders';
		self::$monokulo->respond('GET', '/api/v1', 200, $entry);
		$order = Api::order('order_2');
		$order['_links']['mk:checkout']['href'] = '/pay-here/order_2';
		self::$monokulo->respond('POST', '/elsewhere/orders', 201, $order);

		$made = (new Client(new Trace()))->createOrder($this->connection(), []);

		$this->assertSame(self::$monokulo->url . '/pay-here/order_2', $made['checkout']);
		$this->assertSame('/elsewhere/orders', self::$monokulo->requests()[1]['path']);
	}

	/** An entry that offers the key no form doesn't know the key: connect again. */
	public function test_an_entry_without_the_form_is_a_rejected_key(): void {
		$entry = Api::entry();
		unset($entry['_templates']);
		self::$monokulo->respond('GET', '/api/v1', 200, $entry);

		try {
			(new Client(new Trace()))->createOrder($this->connection(), []);
			$this->fail('no error');
		} catch (ApiError $e) {
			$this->assertSame(401, $e->status);
			$this->assertStringContainsString('connect the store again', $e->merchantHint());
			$this->assertCount(1, self::$monokulo->requests(), 'nothing was posted');
		}
	}

	public static function failures(): array {
		return [
			'rate limited'  => [429, Api::problem('rate-limited', 429, 'Too many requests', 'slow down'), 'slow down', 'error_busy'],
			'bad key'       => [401, Api::problem('unauthorized', 401, 'No valid key', 'Send a key.'), 'Send a key.', 'error_unavailable'],
			'forbidden'     => [403, Api::problem('forbidden', 403, 'Not allowed with this key', 'nope'), 'nope', 'error_unavailable'],
			'not priced'    => [400, Api::problem('invalid-amount', 400, 'The order can\'t be priced', 'unknown currency: NOPE'), 'unknown currency: NOPE', 'error_currency'],
			'title only'    => [409, ['type' => 'https://docs.monokulo.com/problems/idempotency-conflict', 'title' => 'Key used for another order', 'status' => 409], 'Key used for another order', 'error_generic'],
			'not JSON'      => [500, 'oops', 'oops', 'error_generic'],
			'wrong shape'   => [201, '{"id":"x"}', 'missing order_id or its mk:checkout link: {"id":"x"}', 'error_generic'],
			'no checkout'   => [201, '{"order_id":"x"}', 'missing order_id or its mk:checkout link: {"order_id":"x"}', 'error_generic'],
			'not an object' => [201, 'null', 'not JSON: null', 'error_generic'],
			'200 is not made' => [200, '{"order_id":"x"}', '{"order_id":"x"}', 'error_generic'],
		];
	}

	#[DataProvider('failures')]
	public function test_a_failed_order_says_what_to_tell_the_customer(int $status, mixed $body, string $detail, string $customer): void {
		self::$monokulo->api('', $status, $body);

		try {
			(new Client(new Trace()))->createOrder($this->connection(), []);
			$this->fail('no error');
		} catch (ApiError $e) {
			$this->assertSame($status, $e->status);
			$this->assertSame($detail, $e->detail);
			$this->assertSame($customer, $e->customerMessageKey());
		}
	}

	/** An entry that isn't there: an old Monokulo, or the wrong address. */
	public function test_no_api_at_the_address_says_so(): void {
		try {
			(new Client(new Trace()))->createOrder($this->connection(), []);
			$this->fail('no error');
		} catch (ApiError $e) {
			$this->assertSame(404, $e->status);
			$this->assertStringContainsString('no Monokulo API at this address', $e->merchantHint());
		}
	}

	public function test_a_hint_for_the_merchant_on_the_problems_that_have_one(): void {
		$this->assertStringContainsString('connect the store again', (new ApiError(401, ''))->merchantHint());
		$this->assertStringContainsString('connect the store again', (new ApiError(403, ''))->merchantHint());
		$this->assertStringContainsString('Monokulo address', (new ApiError(404, ''))->merchantHint());
		$this->assertStringContainsString('rate limited', (new ApiError(429, ''))->merchantHint());
		$this->assertStringContainsString('exchange rate', (new ApiError(400, '', 'https://docs.monokulo.com/problems/invalid-amount'))->merchantHint());
		$this->assertSame('invalid-amount', (new ApiError(400, '', 'https://docs.monokulo.com/problems/invalid-amount'))->problemName());
		$this->assertSame('', (new ApiError(500, ''))->merchantHint());
	}

	public function test_nothing_listening_is_a_transport_error(): void {
		$connection = new Connection('shop.example', 'http://127.0.0.1:' . FakeMonokulo::freePort() . '/api/v1', 'pk', 'sk', 'wh', 1);

		$this->expectException(TransportError::class);

		(new Client(new Trace()))->createOrder($connection, []);
	}

	public function test_errors_are_forwarded_with_the_secret_key(): void {
		self::$monokulo->respond('POST', '/api/v1/logs', 204, '');
		$entries = [['level' => 'error', 'message' => 'm', 'trace_id' => str_repeat('a', 32)]];

		(new Client(new Trace()))->forwardLogs($this->connection(), $entries);

		$request = self::$monokulo->requests()[0];
		$this->assertSame(['POST', '/api/v1/logs'], [$request['method'], $request['path']]);
		$this->assertSame('Bearer sk_test', $request['headers']['authorization']);
		$this->assertSame('prestashop/' . Plugin::VERSION, $request['headers']['monokulo-client']);
		$this->assertSame(['entries' => $entries], json_decode($request['body'], true));
	}

	public function test_nothing_to_forward_sends_nothing_and_a_dead_monokulo_is_ignored(): void {
		(new Client(new Trace()))->forwardLogs($this->connection(), []);
		$this->assertSame([], self::$monokulo->requests());

		$dead = new Connection('shop.example', 'http://127.0.0.1:' . FakeMonokulo::freePort() . '/api/v1', 'pk', 'sk', 'wh', 1);
		(new Client(new Trace()))->forwardLogs($dead, [['level' => 'error', 'message' => 'm', 'trace_id' => 'x']]);
		$this->addToAssertionCount(1);
	}
}
