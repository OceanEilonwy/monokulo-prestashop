<?php
namespace Monokulo\PrestaShop\Tests\Unit;

use Monokulo\PrestaShop\ApiError;
use Monokulo\PrestaShop\Client;
use Monokulo\PrestaShop\Connection;
use Monokulo\PrestaShop\Plugin;
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
		return new Connection('shop.example', self::$monokulo->url, 'pk_test', 'sk_test', 'whsec_test', 1);
	}

	public function test_finishing_connect_sends_the_token_webhook_and_name(): void {
		self::$monokulo->respond('POST', '/plugins/connect/prestashop/finish', 200, ['public_key' => 'pk_1', 'secret_token' => 'sk_1', 'endpoint' => 'https://pay.example.com', 'webhook_signing_secret' => 'whsec_1']);
		$trace = new Trace();

		$credentials = (new Client($trace))->finishConnect(self::$monokulo->url . '/', 'tok', 'https://shop.example/module/monokulo/webhook');

		$this->assertSame(['public_key' => 'pk_1', 'secret_token' => 'sk_1', 'endpoint' => 'https://pay.example.com', 'webhook_signing_secret' => 'whsec_1'], $credentials);
		$request = self::$monokulo->requests()[0];
		$this->assertSame(['token' => 'tok', 'webhook_url' => 'https://shop.example/module/monokulo/webhook', 'name' => 'PrestaShop'], json_decode($request['body'], true));
		$this->assertSame('prestashop/' . Plugin::VERSION, $request['headers']['monokulo-client']);
		$this->assertSame('application/json', $request['headers']['content-type']);
		$this->assertStringStartsWith('00-' . $trace->id() . '-', $request['headers']['traceparent']);
		$this->assertArrayNotHasKey('authorization', $request['headers']);
	}

	/** Without a webhook secret the shop could never trust a webhook: that's no connection. */
	public function test_finishing_without_every_credential_fails(): void {
		self::$monokulo->respond('POST', '/plugins/connect/prestashop/finish', 200, ['public_key' => 'pk_1', 'secret_token' => 'sk_1', 'endpoint' => 'https://pay.example.com']);

		$this->expectException(ApiError::class);
		$this->expectExceptionMessage('missing webhook_signing_secret');

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

	public function test_creating_an_order_uses_the_secret_key(): void {
		self::$monokulo->respond('POST', '/pay/pk_test/orders', 200, ['order_id' => 'ord_1', 'address' => '4...', 'expires_at' => 1]);

		$order = (new Client(new Trace()))->createOrder($this->connection(), ['amount' => '1.00', 'currency' => 'EUR']);

		$this->assertSame('ord_1', $order['order_id']);
		$request = self::$monokulo->requests()[0];
		$this->assertSame('Bearer sk_test', $request['headers']['authorization']);
		$this->assertSame('prestashop/' . Plugin::VERSION, $request['headers']['monokulo-client']);
		$this->assertSame(['amount' => '1.00', 'currency' => 'EUR'], json_decode($request['body'], true));
	}

	public static function failures(): array {
		return [
			'rate limited'  => [429, '{"error":"slow down"}', 'slow down', 'error_busy'],
			'bad key'       => [401, '{"error":"unauthorized"}', 'unauthorized', 'error_unavailable'],
			'refused'       => [403, '{"error":"domain"}', 'domain', 'error_unavailable'],
			'not JSON'      => [500, 'oops', 'oops', 'error_generic'],
			'wrong shape'   => [200, '{"id":"x"}', 'missing order_id: {"id":"x"}', 'error_generic'],
			'not an object' => [200, 'null', 'not JSON: null', 'error_generic'],
		];
	}

	#[DataProvider('failures')]
	public function test_a_failed_order_says_what_to_tell_the_customer(int $status, string $body, string $detail, string $customer): void {
		self::$monokulo->respond('POST', '/pay/pk_test/orders', $status, $body);

		try {
			(new Client(new Trace()))->createOrder($this->connection(), []);
			$this->fail('no error');
		} catch (ApiError $e) {
			$this->assertSame($status, $e->status);
			$this->assertSame($detail, $e->detail);
			$this->assertSame($customer, $e->customerMessageKey());
		}
	}

	public function test_a_hint_for_the_merchant_on_the_statuses_that_have_one(): void {
		$this->assertStringContainsString('connect the store again', (new ApiError(401, ''))->merchantHint());
		$this->assertStringContainsString('domain', (new ApiError(403, ''))->merchantHint());
		$this->assertStringContainsString('public key', (new ApiError(404, ''))->merchantHint());
		$this->assertStringContainsString('rate limited', (new ApiError(429, ''))->merchantHint());
		$this->assertSame('', (new ApiError(500, ''))->merchantHint());
	}

	public function test_nothing_listening_is_a_transport_error(): void {
		$connection = new Connection('shop.example', 'http://127.0.0.1:' . FakeMonokulo::freePort(), 'pk', 'sk', 'wh', 1);

		$this->expectException(TransportError::class);

		(new Client(new Trace()))->createOrder($connection, []);
	}

	public function test_errors_are_forwarded_with_the_secret_key(): void {
		self::$monokulo->respond('POST', '/pay/pk_test/logs', 204, '');
		$entries = [['level' => 'error', 'message' => 'm', 'trace_id' => str_repeat('a', 32)]];

		(new Client(new Trace()))->forwardLogs($this->connection(), $entries);

		$request = self::$monokulo->requests()[0];
		$this->assertSame('/pay/pk_test/logs', $request['path']);
		$this->assertSame('Bearer sk_test', $request['headers']['authorization']);
		$this->assertSame(['entries' => $entries], json_decode($request['body'], true));
	}

	public function test_nothing_to_forward_sends_nothing_and_a_dead_monokulo_is_ignored(): void {
		(new Client(new Trace()))->forwardLogs($this->connection(), []);
		$this->assertSame([], self::$monokulo->requests());

		$dead = new Connection('shop.example', 'http://127.0.0.1:' . FakeMonokulo::freePort(), 'pk', 'sk', 'wh', 1);
		(new Client(new Trace()))->forwardLogs($dead, [['level' => 'error', 'message' => 'm', 'trace_id' => 'x']]);
		$this->addToAssertionCount(1);
	}
}
