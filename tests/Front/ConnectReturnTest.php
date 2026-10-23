<?php
namespace Monokulo\PrestaShop\Tests\Front;

use Monokulo\PrestaShop\Plugin;
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

	private const CREDENTIALS = ['public_key' => 'pk_new', 'secret_token' => 'sk_new', 'endpoint' => 'https://pay.example.com', 'webhook_signing_secret' => 'whsec_new'];

	/** @return array{redirect: string}|array{status: int, body: string} */
	private function comeBack(array $query, int $shopId = 1): array {
		PrestaShop::useShop($shopId);

		return $this->module()->connectReturn((string)($query['token'] ?? ''), (string)($query['nonce'] ?? ''));
	}

	private function nonce(string $host = self::HOST, int $shopId = 1, int $now = 0): string {
		return $this->repository()->createNonce($shopId, $host, self::ADMIN, $now ?: time());
	}

	private function monokuloFinishes(int $status, mixed $body): void {
		self::$monokulo->respond('POST', '/plugins/connect/prestashop/finish', $status, $body);
	}

	public function test_a_good_return_connects_the_shop_and_offers_monero(): void {
		$this->monokuloFinishes(200, self::CREDENTIALS);
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
		$this->assertSame(['https://pay.example.com', 'pk_new', 'sk_new', 'whsec_new', Plugin::CONNECTION_VERSION], [$connection->endpoint, $connection->publicKey, $connection->secretToken, $connection->webhookSecret, $connection->version]);
		$this->assertTrue($this->module()->isConnected());
	}

	/** Connecting again replaces the shop's credentials (Monokulo replaced its webhook too). */
	public function test_connecting_again_replaces_the_credentials(): void {
		$this->connect();
		$this->monokuloFinishes(200, self::CREDENTIALS);

		$this->comeBack(['token' => 'tok_1', 'nonce' => $this->nonce()]);

		$this->assertSame('pk_new', $this->repository()->connection(self::HOST)->publicKey);
		$this->assertCount(1, $this->rows('SELECT * FROM `' . _DB_PREFIX_ . 'monokulo_connection`'));
	}

	/** The second shop's return lands on its own host and connects only it, with its own webhook address. */
	public function test_a_second_shop_connects_on_its_own_host(): void {
		$shopId = $this->addSecondShop();
		$this->monokuloFinishes(200, self::CREDENTIALS);

		$answer = $this->comeBack(['token' => 'tok_1', 'nonce' => $this->nonce(self::SECOND_HOST, $shopId)], $shopId);

		$this->assertStringEndsWith('&monokulo_connected=1', $answer['redirect']);
		$this->assertNotNull($this->repository()->connection(self::SECOND_HOST));
		$this->assertNull($this->repository()->connection(self::HOST));
		$this->assertSame('http://127.0.0.1:8093/index.php?fc=module&module=monokulo&controller=webhook', json_decode(self::$monokulo->requests()[0]['body'], true)['webhook_url']);
	}

	public function test_a_nonce_works_once(): void {
		$this->monokuloFinishes(200, self::CREDENTIALS);
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
			'no public address yet' => [503, ['error' => 'no public address'], 'unavailable'],
			'token refused'         => [401, '', 'finish'],
			'missing secret'        => [200, ['public_key' => 'pk', 'secret_token' => 'sk', 'endpoint' => 'https://x'], 'finish'],
		];
	}

	#[DataProvider('finishFailures')]
	public function test_when_monokulo_does_not_hand_over_the_keys_the_admin_is_told(int $status, mixed $body, string $error): void {
		$this->monokuloFinishes($status, $body);

		$answer = $this->comeBack(['token' => 'tok_1', 'nonce' => $this->nonce()]);

		$this->assertSame(['redirect' => self::ADMIN . '&monokulo_connect_error=' . $error], $answer);
		$this->assertNull($this->repository()->connection(self::HOST));
	}

	public function test_an_unreachable_monokulo_is_reported(): void {
		$this->setting(\Monokulo::ADDRESS, 'http://127.0.0.1:' . FakeMonokulo::freePort());

		$answer = $this->comeBack(['token' => 'tok_1', 'nonce' => $this->nonce()]);

		$this->assertSame(['redirect' => self::ADMIN . '&monokulo_connect_error=unreachable'], $answer);
	}
}
