<?php
namespace Monokulo\PrestaShop\Tests\Unit;

use Monokulo\PrestaShop\ApiError;
use Monokulo\PrestaShop\Client;
use Monokulo\PrestaShop\Connection;
use Monokulo\PrestaShop\Entry;
use Monokulo\PrestaShop\EntryStore;
use Monokulo\PrestaShop\Plugin;
use Monokulo\PrestaShop\Tests\Support\Api;
use Monokulo\PrestaShop\Tests\Support\FakeMonokulo;
use Monokulo\PrestaShop\Trace;
use Monokulo\PrestaShop\TransportError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The client against a stand-in Monokulo over real HTTP on this machine,
 * answering with the exchanges Monokulo's OpenAPI document records.
 */
final class ClientTest extends TestCase {
	private static FakeMonokulo $monokulo;

	/** @var \ArrayObject<int, Entry> what the client kept, in order */
	private \ArrayObject $kept;

	public static function setUpBeforeClass(): void {
		self::$monokulo = new FakeMonokulo();
	}

	public static function tearDownAfterClass(): void {
		self::$monokulo->stop();
	}

	protected function setUp(): void {
		self::$monokulo->reset();
		$this->kept = new \ArrayObject();
	}

	private function connection(string $prefix = '', ?Entry $entry = null): Connection {
		return new Connection('shop.example', self::$monokulo->url . $prefix . '/api/v1', '', 'pk_test', 'sk_test', 'whsec_test', 1, $entry);
	}

	private function client(): Client {
		return new Client(new Trace(), new class($this->kept) implements EntryStore {
			public function __construct(private readonly \ArrayObject $kept) {
			}

			public function keepEntry(Connection $connection, Entry $entry): void {
				$this->kept[] = $entry;
			}
		});
	}

	/** @return list<string> `METHOD path` of each request the stand-in got */
	private function requests(): array {
		return array_map(static fn(array $r): string => $r['method'] . ' ' . $r['path'], self::$monokulo->requests());
	}

	/** `api` is a path on the Monokulo the token was redeemed at; `onion_api` its entry over Tor. */
	public function test_finishing_connect_sends_the_token_webhook_and_name(): void {
		$answer = Api::finished();
		self::$monokulo->respond('POST', '/plugins/connect/prestashop/finish', 200, $answer);
		$trace = new Trace();

		$credentials = (new Client($trace))->finishConnect(self::$monokulo->url . '/', 'tok', 'https://shop.example/module/monokulo/webhook');

		$this->assertSame([
			'public_key'             => $answer['public_key'],
			'secret_token'           => $answer['secret_token'],
			'api'                    => self::$monokulo->url . '/api/v1',
			'onion_api'              => Api::ONION . '/api/v1',
			'webhook_signing_secret' => $answer['webhook_signing_secret'],
		], $credentials);
		$request = self::$monokulo->requests()[0];
		$this->assertSame(['token' => 'tok', 'webhook_url' => 'https://shop.example/module/monokulo/webhook', 'name' => 'PrestaShop'], json_decode($request['body'], true));
		$this->assertSame('prestashop/' . Plugin::VERSION, $request['headers']['monokulo-client']);
		$this->assertSame('application/json', $request['headers']['content-type']);
		$this->assertStringStartsWith('00-' . $trace->id() . '-', $request['headers']['traceparent']);
		$this->assertArrayNotHasKey('authorization', $request['headers']);
	}

	public function test_a_monokulo_without_an_onion_address_gives_none(): void {
		self::$monokulo->respond('POST', '/plugins/connect/prestashop/finish', 200, Api::finished(onion: false));

		$this->assertNull((new Client(new Trace()))->finishConnect(self::$monokulo->url, 'tok', 'https://shop.example/')['onion_api']);
	}

	/** A Monokulo served under a path: the handshake is under it, and so is the entry it gives. */
	public function test_finishing_connect_under_a_path_keeps_the_path(): void {
		self::$monokulo->respond('POST', '/monokulo/plugins/connect/prestashop/finish', 200, Api::under('/monokulo', Api::finished()));

		$credentials = (new Client(new Trace()))->finishConnect(self::$monokulo->url . '/monokulo/', 'tok', 'https://shop.example/');

		$this->assertSame(self::$monokulo->url . '/monokulo/api/v1', $credentials['api']);
		$this->assertSame(Api::ONION . '/api/v1', $credentials['onion_api'], 'onion links have no path');
	}

	/** Without a webhook secret the shop could never trust a webhook, and without the API's entry it can't make orders: that's no connection. */
	public function test_finishing_without_every_credential_fails(): void {
		foreach (['webhook_signing_secret', 'api', 'secret_token', 'public_key'] as $missing) {
			$answer = Api::finished();
			unset($answer[$missing]);
			self::$monokulo->respond('POST', '/plugins/connect/prestashop/finish', 200, $answer);
			try {
				(new Client(new Trace()))->finishConnect(self::$monokulo->url, 'tok', 'https://shop.example/');
				$this->fail('no error without ' . $missing);
			} catch (ApiError $e) {
				$this->assertStringContainsString('missing ' . $missing, $e->detail);
			}
		}
	}

	/** @return array<string, array{string}> every status the handshake's problems come with */
	public static function handshakeStatuses(): array {
		$out = [];
		foreach (array_keys(Api::operation('post', '/plugins/connect/{slug}/finish')['responses']) as $status) {
			if ((string)$status !== '200') {
				$out[(string)$status] = [(string)$status];
			}
		}

		return $out;
	}

	/** Every way the handshake can fail is a problem: its status, `detail` and `type` reach the log. */
	#[DataProvider('handshakeStatuses')]
	public function test_a_refused_handshake_says_why(string $status): void {
		$content = Api::operation('post', '/plugins/connect/{slug}/finish')['responses'][$status]['content'];
		$this->assertSame(['application/problem+json'], array_keys($content));
		$problem = array_values($content['application/problem+json']['examples'] ?? [])[0]['value'] ?? Api::problem('x', (int)$status, 'Title ' . $status, 'detail ' . $status);
		self::$monokulo->respond('POST', '/plugins/connect/prestashop/finish', (int)$status, $problem, headers: ['Content-Type' => 'application/problem+json']);

		try {
			(new Client(new Trace()))->finishConnect(self::$monokulo->url, 'tok', 'https://shop.example/');
			$this->fail('no error');
		} catch (ApiError $e) {
			$this->assertSame((int)$status, $e->status);
			$this->assertSame($problem['detail'], $e->detail);
			$this->assertSame($problem['type'], $e->problem);
		}
	}

	/**
	 * The client reads the entry with the store's key, fills in its
	 * `create-order` form, and hands back the order's links, resolved
	 * against Monokulo's address.
	 */
	public function test_creating_an_order_follows_the_entrys_form(): void {
		self::$monokulo->api('order_1');

		$order = $this->client()->createOrder($this->connection(), ['amount' => '1', 'currency' => 'EUR']);

		$this->assertSame([
			'order_id'       => 'order_1',
			'checkout'       => self::$monokulo->url . '/checkout/order_1',
			'onion_checkout' => Api::ONION . '/checkout/order_1',
		], $order);
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

	/** The entry links itself on the onion address only while Monokulo has one; the kept entry answers. */
	public function test_the_entry_says_whether_monokulo_has_an_onion_address(): void {
		self::$monokulo->entry(Api::entry(onion: false));
		$this->assertFalse($this->client()->hasOnion($this->connection()));

		self::$monokulo->reset();
		self::$monokulo->entry(Api::entry());
		$client = $this->client();
		$this->assertTrue($client->hasOnion($this->connection()));
		$this->assertTrue($client->hasOnion($this->connection()));
		$this->assertSame(['GET /api/v1'], $this->requests(), 'kept');
	}

	/** A kept entry without an onion address is checked again before saying no: Monokulo may have gained one. */
	public function test_a_kept_entry_without_an_onion_address_is_checked_again(): void {
		$kept = new Entry(Api::entry(onion: false), '"entry-0"', time() + 3600);
		self::$monokulo->entry(Api::entry());

		$this->assertTrue($this->client()->hasOnion($this->connection(entry: $kept)));
		$this->assertSame(['GET /api/v1'], $this->requests());
		$this->assertSame('"entry-0"', self::$monokulo->requests()[0]['headers']['if-none-match']);

		// Still none: the 304 keeps the entry, and the answer is no.
		self::$monokulo->reset();
		self::$monokulo->entry(Api::entry(onion: false), etag: '"entry-0"');
		$this->assertFalse($this->client()->hasOnion($this->connection(entry: $kept)));
		$this->assertSame(['GET /api/v1'], $this->requests());
	}

	public function test_an_order_from_a_monokulo_without_an_onion_address_has_no_onion_checkout(): void {
		self::$monokulo->api('order_1', onion: false);

		$this->assertNull($this->client()->createOrder($this->connection(), [])['onion_checkout']);
	}

	/** Under a path, every link and form target carries it: resolved, never built. */
	public function test_a_monokulo_under_a_path_is_followed_there(): void {
		self::$monokulo->api('order_1', prefix: '/monokulo');

		$order = $this->client()->createOrder($this->connection('/monokulo'), []);

		$this->assertSame(self::$monokulo->url . '/monokulo/checkout/order_1', $order['checkout']);
		$this->assertSame(Api::ONION . '/checkout/order_1', $order['onion_checkout']);
		$this->assertSame(['GET /monokulo/api/v1', 'POST /monokulo/api/v1/orders'], $this->requests());
	}

	/** Where the form says to send it is where it goes: no address past the entry is the client's own. */
	public function test_the_form_decides_where_the_order_goes(): void {
		$entry = Api::entry();
		$entry['_templates']['create-order']['target'] = '/elsewhere/orders';
		self::$monokulo->entry($entry);
		$order = Api::order('order_2');
		$order['_links']['mk:checkout']['href'] = '/pay-here/order_2';
		self::$monokulo->respond('POST', '/elsewhere/orders', 201, $order);

		$made = $this->client()->createOrder($this->connection(), []);

		$this->assertSame(self::$monokulo->url . '/pay-here/order_2', $made['checkout']);
		$this->assertSame('/elsewhere/orders', self::$monokulo->requests()[1]['path']);
	}

	/** The entry is kept for its max-age, so the next order is one request. */
	public function test_the_entry_is_kept_for_as_long_as_monokulo_says(): void {
		self::$monokulo->api('order_1');
		$client = $this->client();

		$client->createOrder($this->connection(), []);
		$kept = $this->kept[0];
		$this->assertSame('"entry-1"', $kept->etag);
		$this->assertEqualsWithDelta(time() + 3600, $kept->expiresAt, 5);
		$this->assertEquals(Api::entry(), $kept->body);

		// The same request, and a later one with the entry it kept.
		$client->createOrder($this->connection(), []);
		$this->client()->createOrder($this->connection(entry: $kept), []);
		$this->assertSame(['GET /api/v1', 'POST /api/v1/orders', 'POST /api/v1/orders', 'POST /api/v1/orders'], $this->requests());
		$this->assertCount(1, $this->kept);
	}

	/** Expired, it is asked for again with its ETag: unchanged, it is kept for another max-age. */
	public function test_an_expired_entry_is_checked_with_its_etag(): void {
		self::$monokulo->api('order_1');
		$expired = new Entry(Api::entry(), '"entry-1"', time() - 1);

		$this->client()->createOrder($this->connection(entry: $expired), []);

		$this->assertSame(['GET /api/v1', 'POST /api/v1/orders'], $this->requests());
		$this->assertSame('"entry-1"', self::$monokulo->requests()[0]['headers']['if-none-match']);
		$this->assertEquals(Api::entry(), $this->kept[0]->body, 'kept from the 304');
		$this->assertEqualsWithDelta(time() + 3600, $this->kept[0]->expiresAt, 5);
	}

	/** @return array<string, array{int}> */
	public static function staleAnswers(): array {
		return ['401' => [401], '404' => [404], '405' => [405]];
	}

	/** A form refused with 401, 404 or 405: the API may have moved on. The entry is read again, and the new form used. */
	#[DataProvider('staleAnswers')]
	public function test_a_refused_form_reads_the_entry_again(int $status): void {
		$old = Api::entry();
		$old['_templates']['create-order']['target'] = '/api/v0/orders';
		$kept = new Entry($old, '"entry-0"', time() + 3600);
		self::$monokulo->api('order_1');
		self::$monokulo->respond('POST', '/api/v0/orders', $status, Api::problem('gone', $status, 'Gone', 'moved on'), headers: ['Content-Type' => 'application/problem+json']);

		$order = $this->client()->createOrder($this->connection(entry: $kept), []);

		$this->assertSame('order_1', $order['order_id']);
		$this->assertSame(['POST /api/v0/orders', 'GET /api/v1', 'POST /api/v1/orders'], $this->requests());
		$this->assertSame('"entry-0"', self::$monokulo->requests()[1]['headers']['if-none-match']);
		$this->assertSame('"entry-1"', $this->kept[0]->etag);
	}

	/** Read again and unchanged, the form's own answer stands: it isn't sent twice. */
	public function test_a_refused_form_with_the_same_entry_is_the_answer(): void {
		self::$monokulo->api('', 405, Api::problem('method-not-allowed', 405, 'Method not allowed', 'not here'));

		try {
			$this->client()->createOrder($this->connection(entry: new Entry(Api::entry(), '"entry-1"', time() + 3600)), []);
			$this->fail('no error');
		} catch (ApiError $e) {
			$this->assertSame(405, $e->status);
			$this->assertSame(['POST /api/v1/orders', 'GET /api/v1'], $this->requests());
		}
	}

	/** A key Monokulo doesn't know (replaced, or the store disconnected) is 401 at the entry: connect again. */
	public function test_an_unknown_key_is_refused_at_the_entry(): void {
		$unauthorized = Api::recordedProblem('get', '/api/v1', 'unauthorized');
		self::$monokulo->respond('GET', '/api/v1', 401, $unauthorized, headers: ['Content-Type' => 'application/problem+json']);

		try {
			$this->client()->createOrder($this->connection(), []);
			$this->fail('no error');
		} catch (ApiError $e) {
			$this->assertSame(401, $e->status);
			$this->assertSame($unauthorized['detail'], $e->detail);
			$this->assertStringContainsString('connect the store again', $e->merchantHint());
			$this->assertSame(['GET /api/v1'], $this->requests(), 'nothing was posted');
		}
	}

	public static function failures(): array {
		return [
			'rate limited'  => [429, Api::problem('rate-limited', 429, 'Too many requests', 'slow down'), 'slow down', 'error_busy'],
			'no rate'       => [503, Api::problem('rates-unavailable', 503, 'No exchange rate right now', 'no EUR rate'), 'no EUR rate', 'error_try_again'],
			'engine busy'   => [503, Api::problem('engine-unavailable', 503, 'Payment engine unavailable', 'busy'), 'busy', 'error_try_again'],
			'503, no problem' => [503, 'Service Unavailable', 'Service Unavailable', 'error_try_again'],
			'forbidden'     => [403, Api::problem('forbidden', 403, 'Not allowed with this key', 'nope'), 'nope', 'error_unavailable'],
			'not priced'    => [400, Api::recordedProblem('post', '/api/v1/orders', 'invalid-amount'), Api::recordedProblem('post', '/api/v1/orders', 'invalid-amount')['detail'], 'error_currency'],
			'invalid'       => [400, Api::recordedProblem('post', '/api/v1/orders', 'invalid-request'), Api::recordedProblem('post', '/api/v1/orders', 'invalid-request')['detail'], 'error_generic'],
			'title only'    => [409, ['type' => 'https://docs.monokulo.com/problems/idempotency-conflict', 'title' => 'Key used for another order', 'status' => 409], 'Key used for another order', 'error_generic'],
			'not a problem' => [500, 'oops', 'oops', 'error_generic'],
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
			$this->client()->createOrder($this->connection(), []);
			$this->fail('no error');
		} catch (ApiError $e) {
			$this->assertSame($status, $e->status);
			$this->assertSame($detail, $e->detail);
			$this->assertSame($customer, $e->customerMessageKey());
		}
	}

	/**
	 * No exchange rate right now: nothing was made, and Monokulo may say how
	 * long to wait, in the problem's `retry_after` or in `Retry-After`. The
	 * merchant's log says what happened and that the customer was asked to
	 * try again.
	 */
	public function test_a_try_again_problem_says_how_long_to_wait(): void {
		$problem = Api::problem('rates-unavailable', 503, 'No exchange rate right now', 'no EUR rate') + ['retry_after' => 30];
		self::$monokulo->entry(Api::entry());
		self::$monokulo->respond('POST', '/api/v1/orders', 503, $problem, headers: ['Content-Type' => 'application/problem+json', 'Retry-After' => '30']);

		try {
			$this->client()->createOrder($this->connection(), []);
			$this->fail('no error');
		} catch (ApiError $e) {
			$this->assertSame(30, $e->retryAfter);
			$this->assertTrue($e->tryAgain());
			$this->assertStringContainsString('no exchange rate for this currency right now', $e->merchantHint());
			$this->assertStringContainsString('No Monokulo order was made', $e->merchantHint());
			$this->assertStringContainsString('after 30 seconds', $e->merchantHint());
		}

		// Only the header: it is read from there.
		self::$monokulo->reset();
		self::$monokulo->entry(Api::entry());
		self::$monokulo->respond('POST', '/api/v1/orders', 503, Api::problem('engine-unavailable', 503, 'Payment engine unavailable', 'busy'), headers: ['Content-Type' => 'application/problem+json', 'Retry-After' => '5']);
		try {
			$this->client()->createOrder($this->connection(), []);
			$this->fail('no error');
		} catch (ApiError $e) {
			$this->assertSame(5, $e->retryAfter);
			$this->assertStringContainsString('payment engine is unavailable', $e->merchantHint());
		}

		// Neither: no wait is claimed.
		self::$monokulo->reset();
		self::$monokulo->api('', 503, Api::problem('engine-unavailable', 503, 'Payment engine unavailable', 'busy'));
		try {
			$this->client()->createOrder($this->connection(), []);
			$this->fail('no error');
		} catch (ApiError $e) {
			$this->assertNull($e->retryAfter);
			$this->assertStringNotContainsString('seconds', $e->merchantHint());
		}
	}

	/** An entry that isn't there: the wrong address. */
	public function test_no_api_at_the_address_says_so(): void {
		try {
			$this->client()->createOrder($this->connection(), []);
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
		$connection = new Connection('shop.example', 'http://127.0.0.1:' . FakeMonokulo::freePort() . '/api/v1', '', 'pk', 'sk', 'wh', 1);

		$this->expectException(TransportError::class);

		$this->client()->createOrder($connection, []);
	}

	/** Errors go with the entry's `send-logs` form, which only the secret key is offered. */
	public function test_errors_are_forwarded_with_the_send_logs_form(): void {
		self::$monokulo->entry(Api::entry());
		self::$monokulo->respond('POST', '/api/v1/logs', 204, '');
		$entries = [['level' => 'error', 'message' => 'm', 'trace_id' => str_repeat('a', 32)]];

		$this->client()->forwardLogs($this->connection(), $entries);

		$this->assertSame(['GET /api/v1', 'POST /api/v1/logs'], $this->requests());
		$request = self::$monokulo->requests()[1];
		$this->assertSame('Bearer sk_test', $request['headers']['authorization']);
		$this->assertSame('prestashop/' . Plugin::VERSION, $request['headers']['monokulo-client']);
		$this->assertSame(['entries' => $entries], json_decode($request['body'], true));
	}

	public function test_an_entry_without_the_send_logs_form_gets_no_logs(): void {
		$entry = Api::entry();
		unset($entry['_templates']['send-logs']);
		self::$monokulo->entry($entry);

		$this->client()->forwardLogs($this->connection(), [['level' => 'error', 'message' => 'm', 'trace_id' => 'x']]);

		$this->assertSame(['GET /api/v1'], $this->requests());
	}

	public function test_nothing_to_forward_sends_nothing_and_a_dead_monokulo_is_ignored(): void {
		$this->client()->forwardLogs($this->connection(), []);
		$this->assertSame([], self::$monokulo->requests());

		$dead = new Connection('shop.example', 'http://127.0.0.1:' . FakeMonokulo::freePort() . '/api/v1', '', 'pk', 'sk', 'wh', 1);
		$this->client()->forwardLogs($dead, [['level' => 'error', 'message' => 'm', 'trace_id' => 'x']]);
		self::$monokulo->respond('POST', '/api/v1/logs', 403, Api::recordedProblem('post', '/api/v1/logs', 'logs-off'));
		self::$monokulo->entry(Api::entry());
		$this->client()->forwardLogs($this->connection(), [['level' => 'error', 'message' => 'm', 'trace_id' => 'x']]);
		$this->addToAssertionCount(1);
	}
}
