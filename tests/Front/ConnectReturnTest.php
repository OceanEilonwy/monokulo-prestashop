<?php
namespace Monokulo\PrestaShop\Tests\Front;

use Monokulo\PrestaShop\Plugin;
use Monokulo\PrestaShop\Tests\Support\Api;
use Monokulo\PrestaShop\Tests\Support\FakeMonokulo;
use Monokulo\PrestaShop\Tests\Support\PrestaShop;
use Monokulo\PrestaShop\Tests\Support\ShopTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The end of the connect flow, on the shop's own address: Monokulo sends the
 * admin's browser back with a single-use token and the nonce the settings
 * page made, the shop redeems the token for its credentials, and the
 * browser goes back to the settings page saying how it went.
 */
final class ConnectReturnTest extends ShopTestCase {
	private const ADMIN = 'http://localhost:8093/admin-dev/index.php?controller=AdminModules&configure=monokulo&token=abc';


	/** @return array{redirect: string}|array{status: int, body: string} */
	private function comeBack(array $query, int $shopId = 1): array {
		PrestaShop::useShop($shopId);

		return $this->module()->connectReturn((string)($query['token'] ?? ''), (string)($query['nonce'] ?? ''));
	}

	private function nonce(string $host = self::HOST, int $shopId = 1, int $now = 0): string {
		return $this->repository()->createNonce($shopId, $host, self::ADMIN, $now ?: time());
	}

	private function monokuloFinishes(int $status, mixed $body, string $prefix = ''): void {
		self::$monokulo->respond('POST', $prefix . '/plugins/connect/prestashop/finish', $status, $body, headers: $status === 200 ? [] : ['Content-Type' => 'application/problem+json']);
	}

	public function test_a_good_return_connects_the_shop_and_offers_monero(): void {
		$finished = Api::finished();
		$this->monokuloFinishes(200, $finished);
		$this->assertFalse($this->module()->isConnected());

		$answer = $this->comeBack(['token' => 'tok_1', 'nonce' => $this->nonce()]);

		$this->assertSame(['redirect' => self::ADMIN . '&monokulo_connected=1'], $answer);
		$request = self::$monokulo->requests()[0];
		$this->assertSame([
			'token'       => 'tok_1',
			'webhook_url' => 'http://localhost:8093/index.php?fc=module&module=monokulo&controller=webhook',
			'name'        => 'PrestaShop',
		], json_decode($request['body'], true));
		$this->assertSame('prestashop/' . Plugin::VERSION, $request['headers']['monokulo-client']);

		$connection = $this->repository()->connection(self::HOST);
		$this->assertSame([self::$monokulo->url . '/api/v1', Api::ONION . '/api/v1', $finished['public_key'], $finished['secret_token'], $finished['webhook_signing_secret']], [$connection->api, $connection->onionApi, $connection->publicKey, $connection->secretToken, $connection->webhookSecret]);
		$this->assertTrue($this->module()->isConnected());
	}

	public function test_a_monokulo_without_an_onion_address_connects_without_one(): void {
		$this->monokuloFinishes(200, Api::finished(onion: false));

		$this->comeBack(['token' => 'tok_1', 'nonce' => $this->nonce()]);

		$this->assertSame('', $this->repository()->connection(self::HOST)->onionApi);
	}

	/** A Monokulo under a path: the handshake goes there, and the entry it gives keeps the path. */
	public function test_a_monokulo_under_a_path_connects_there(): void {
		$this->setting(\Monokulo::ADDRESS, self::$monokulo->url . '/monokulo');
		$this->monokuloFinishes(200, Api::under('/monokulo', Api::finished()), '/monokulo');

		$answer = $this->comeBack(['token' => 'tok_1', 'nonce' => $this->nonce()]);

		$this->assertSame(['redirect' => self::ADMIN . '&monokulo_connected=1'], $answer);
		$this->assertSame(self::$monokulo->url . '/monokulo/api/v1', $this->repository()->connection(self::HOST)->api);
	}

	/** Connecting again replaces the shop's credentials (Monokulo replaced its webhook too). */
	public function test_connecting_again_replaces_the_credentials(): void {
		$this->connect();
		$this->monokuloFinishes(200, Api::finished());

		$this->comeBack(['token' => 'tok_1', 'nonce' => $this->nonce()]);

		$this->assertSame(Api::finished()['public_key'], $this->repository()->connection(self::HOST)->publicKey);
		$this->assertCount(1, $this->rows('SELECT * FROM `' . _DB_PREFIX_ . 'monokulo_connection`'));
	}

	/** The second shop's return lands on its own host and connects only it, with its own webhook address. */
	public function test_a_second_shop_connects_on_its_own_host(): void {
		$shopId = $this->addSecondShop();
		$this->monokuloFinishes(200, Api::finished());

		$answer = $this->comeBack(['token' => 'tok_1', 'nonce' => $this->nonce(self::SECOND_HOST, $shopId)], $shopId);

		$this->assertStringEndsWith('&monokulo_connected=1', $answer['redirect']);
		$this->assertNotNull($this->repository()->connection(self::SECOND_HOST));
		$this->assertNull($this->repository()->connection(self::HOST));
		$this->assertSame('http://127.0.0.1:8093/index.php?fc=module&module=monokulo&controller=webhook', json_decode(self::$monokulo->requests()[0]['body'], true)['webhook_url']);
	}

	public function test_a_nonce_works_once(): void {
		$this->monokuloFinishes(200, Api::finished());
		$nonce = $this->nonce();
		$this->comeBack(['token' => 'tok_1', 'nonce' => $nonce]);
		$this->db()->execute('DELETE FROM `' . _DB_PREFIX_ . 'monokulo_connection`');

		$again = $this->comeBack(['token' => 'tok_1', 'nonce' => $nonce]);

		$this->assertSame(400, $again['status']);
		$this->assertStringContainsString('already been used or has expired', $again['body']);
		$this->assertNull($this->repository()->connection(self::HOST));
		$this->assertCount(1, self::$monokulo->requests(), 'the token was not sent twice');
	}

	public static function badReturns(): array {
		return [
			'no nonce'      => [['token' => 'tok_1']],
			'unknown nonce' => [['token' => 'tok_1', 'nonce' => str_repeat('a', 32)]],
			'not a nonce'   => [['token' => 'tok_1', 'nonce' => "' OR 1=1 --"]],
		];
	}

	#[DataProvider('badReturns')]
	public function test_a_return_this_shop_did_not_start_is_refused(array $query): void {
		$this->nonce();

		$answer = $this->comeBack($query);

		$this->assertSame(400, $answer['status']);
		$this->assertSame([], self::$monokulo->requests());
	}

	/** Without a token the nonce is still used up: the attempt must start again. */
	public function test_no_token_is_refused_and_spends_the_nonce(): void {
		$nonce = $this->nonce();

		$this->assertSame(400, $this->comeBack(['nonce' => $nonce])['status']);
		$this->assertNull($this->repository()->consumeNonce($nonce, time()));
	}

	public function test_a_nonce_older_than_ten_minutes_is_refused(): void {
		$nonce = $this->nonce(self::HOST, 1, time() - 601);

		$this->assertSame(400, $this->comeBack(['token' => 'tok_1', 'nonce' => $nonce])['status']);
	}

	/** Started for one shop, came back to another: refused, nothing connected. */
	public function test_coming_back_to_another_shop_is_refused(): void {
		$shopId = $this->addSecondShop();

		$answer = $this->comeBack(['token' => 'tok_1', 'nonce' => $this->nonce(self::HOST)], $shopId);

		$this->assertSame(['redirect' => self::ADMIN . '&monokulo_connect_error=host'], $answer);
		$this->assertSame([], self::$monokulo->requests());
	}

	public static function finishFailures(): array {
		return [
			'no public address yet' => [503, Api::recordedProblem('post', '/plugins/connect/{slug}/finish', 'no-public-address'), 'unavailable'],
			'token refused'         => [401, Api::recordedProblem('post', '/plugins/connect/{slug}/finish', 'invalid-connect-code'), 'finish'],
			'body refused'          => [400, Api::recordedProblem('post', '/plugins/connect/{slug}/finish', 'invalid-request'), 'finish'],
			'too many attempts'     => [429, Api::recordedProblem('post', '/plugins/connect/{slug}/finish', 'rate-limited'), 'busy'],
			'not a plugin here'     => [404, Api::recordedProblem('post', '/plugins/connect/{slug}/finish', 'not-found'), 'finish'],
			'Monokulo broke'        => [500, Api::recordedProblem('post', '/plugins/connect/{slug}/finish', 'internal'), 'finish'],
			'missing secret'        => [200, array_diff_key(Api::finished(), ['webhook_signing_secret' => 1]), 'finish'],
			'no API entry'          => [200, array_diff_key(Api::finished(), ['api' => 1]), 'finish'],
		];
	}

	#[DataProvider('finishFailures')]
	public function test_when_monokulo_does_not_hand_over_the_keys_the_admin_is_told(int $status, mixed $body, string $error): void {
		$this->monokuloFinishes($status, $body);

		$answer = $this->comeBack(['token' => 'tok_1', 'nonce' => $this->nonce()]);

		$this->assertSame(['redirect' => self::ADMIN . '&monokulo_connect_error=' . $error], $answer);
		$this->assertNull($this->repository()->connection(self::HOST));
	}

	/** Too many connect attempts: the admin is asked to wait, and the log says how long Monokulo asked. */
	public function test_too_many_connect_attempts_say_how_long_to_wait(): void {
		$problem = Api::recordedProblem('post', '/plugins/connect/{slug}/finish', 'rate-limited');
		$this->monokuloFinishes(429, $problem);
		$before = $this->logSize();

		$this->comeBack(['token' => 'tok_1', 'nonce' => $this->nonce()]);

		$this->assertStringContainsString('HTTP 429: ' . $problem['detail'] . ' (too many connect attempts from this shop: wait ' . $problem['retry_after'] . ' seconds, then connect again)', $this->logSince($before));
	}

	public function test_an_unreachable_monokulo_is_reported(): void {
		$this->setting(\Monokulo::ADDRESS, 'http://127.0.0.1:' . FakeMonokulo::freePort());

		$answer = $this->comeBack(['token' => 'tok_1', 'nonce' => $this->nonce()]);

		$this->assertSame(['redirect' => self::ADMIN . '&monokulo_connect_error=unreachable'], $answer);
	}
}
