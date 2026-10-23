<?php
namespace Monokulo\PrestaShop\Tests\Unit;

use Monokulo\PrestaShop\Logger;
use Monokulo\PrestaShop\OrderRequest;
use Monokulo\PrestaShop\Plugin;
use Monokulo\PrestaShop\Tests\Support\Api;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the module sends and relies on, against Monokulo's OpenAPI document
 * at the pinned commit (Support\Api): the requests fit the schemas, and the
 * links and forms the module follows are the ones Monokulo gives the
 * store's secret key.
 */
final class ContractTest extends TestCase {
	/** @param array<string, mixed> $schema */
	private function properties(array $schema): array {
		return array_keys($schema['properties']);
	}

	public static function amounts(): array {
		return [
			'pounds'           => [12.5, 'GBP', 2],
			'whole'            => [1000.0, 'JPY', 0],
			'Monero'           => [0.123456789012, 'XMR', 12],
			'more than enough' => [1.5, 'EUR', 20],
		];
	}

	/** The order the module makes is one the create-order form takes, field by field. */
	#[DataProvider('amounts')]
	public function test_the_order_fits_the_create_order_form(float $total, string $currency, int $decimals): void {
		$body = OrderRequest::body(1, 1042, 'XKBKNABJK', $total, $currency, $decimals, ['Blue mug', 'Tea'], 'Order XKBKNABJK', ' + %d more');
		$input = Api::schema('OrdersInput');

		$this->assertSame([], array_values(array_diff(array_keys($body), $this->properties($input))), 'every field is one Monokulo takes');
		$this->assertSame([], array_values(array_diff($input['required'] ?? [], array_keys($body))), 'every required field is sent');
		$this->assertMatchesRegularExpression('/' . Api::schema('Amount')['pattern'] . '/', $body['amount']);
		$this->assertMatchesRegularExpression('/' . Api::schema('CurrencyCode')['pattern'] . '/', $body['currency']);
		$this->assertMatchesRegularExpression('/' . Api::schema('IdempotencyKey')['pattern'] . '/', $body['idempotency_key']);
		$this->assertLessThanOrEqual(Api::schema('Description')['maxLength'], mb_strlen($body['description']));

		$form = Api::entry()['_templates']['create-order'];
		$this->assertSame(['POST', '/api/v1/orders', 'application/json'], [$form['method'], $form['target'], $form['contentType']]);
		$this->assertSame([], array_values(array_diff(array_keys($body), array_column($form['properties'], 'name'))), 'the form names every field too');
	}

	/** The secret key's entry offers both forms the module uses, and is kept as Monokulo says. */
	public function test_the_secret_keys_entry_offers_the_modules_forms_and_may_be_kept(): void {
		$this->assertArrayHasKey('create-order', Api::entry()['_templates']);
		$this->assertArrayHasKey('send-logs', Api::entry()['_templates']);
		$this->assertArrayNotHasKey('send-logs', Api::example('get', '/api/v1', '200', 'pk')['_templates'], 'only the secret key may send logs');

		$entry = Api::operation('get', '/api/v1');
		$this->assertSame(['Cache-Control', 'ETag', 'Vary'], array_keys($entry['responses']['200']['headers']));
		$this->assertArrayHasKey('304', $entry['responses']);
		$this->assertContains('If-None-Match', array_column($entry['parameters'] ?? [], 'name'));
	}

	/** What the module forwards fits the send-logs form: at most as many entries as it takes, each with what it needs. */
	public function test_forwarded_logs_fit_the_send_logs_form(): void {
		$form = Api::entry()['_templates']['send-logs'];
		$this->assertSame(['POST', '/api/v1/logs', 'application/json'], [$form['method'], $form['target'], $form['contentType']]);
		$this->assertStringContainsString('at most ' . Logger::MAX_FORWARDED, Api::schema('LogsInput')['description']);

		$entry = Api::schema('LogsEntry');
		$sent = ['level' => 'error', 'message' => 'm', 'trace_id' => str_repeat('a', 32)];
		$this->assertSame([], array_values(array_diff(array_keys($sent), $this->properties($entry))));
		$this->assertSame([], array_values(array_diff($entry['required'], array_keys($sent))));
	}

	/** The order links the module follows: the payer's page, its onion twin, and the merchant's page, all text/html pages Monokulo serves. */
	public function test_an_order_links_the_pages_the_module_sends_people_to(): void {
		$links = Api::order('order_1')['_links'];

		$this->assertSame('/checkout/order_1', $links['mk:checkout']['href']);
		$this->assertSame(Api::ONION . '/checkout/order_1', $links['mk:onion-checkout']['href']);
		$this->assertMatchesRegularExpression('#\A/stores/[^/]+/orders/order_1\z#', $links['mk:dashboard-order']['href']);
		foreach (['mk:checkout', 'mk:onion-checkout', 'mk:dashboard-order'] as $rel) {
			$this->assertSame('text/html', $links[$rel]['type'], $rel);
		}
		$this->assertArrayHasKey('text/html', Api::operation('get', '/checkout/{order_id}')['responses']['200']['content']);
		$this->assertArrayHasKey('text/html', Api::operation('get', '/stores/{id}/orders/{order_id}')['responses']['200']['content']);
		$this->assertArrayNotHasKey('mk:dashboard-order', Api::example('post', '/api/v1/orders', '201', 'pk')['_links'], 'the merchant\'s page is for the secret key only');
	}

	/** The handshake takes what the module sends, and answers what it keeps. */
	public function test_the_handshake_takes_and_gives_what_the_module_uses(): void {
		$finish = Api::schema('PluginConnectFinish');
		$sent = ['token' => 'tok', 'webhook_url' => 'https://shop.example/', 'name' => Plugin::NAME];
		$this->assertSame([], array_values(array_diff(array_keys($sent), $this->properties($finish))));
		$this->assertSame([], array_values(array_diff($finish['required'], array_keys($sent))));

		$connection = Api::schema('PluginConnection');
		$this->assertSame([], array_values(array_diff(['public_key', 'secret_token', 'api', 'onion_api', 'webhook_signing_secret'], $this->properties($connection))));
		$this->assertSame('/api/v1', Api::finished()['api']);
		$this->assertSame(Api::ONION . '/api/v1', Api::finished()['onion_api']);
		$this->assertSame(Api::ONION . '/api/v1', Api::entry()['_links']['mk:onion-api']['href'], 'the entry and the handshake agree');
	}
}
