<?php
namespace Monokulo\PrestaShop\Tests\Unit;

use Monokulo\PrestaShop\Logger;
use Monokulo\PrestaShop\OrderRequest;
use Monokulo\PrestaShop\Plugin;
use Monokulo\PrestaShop\Tests\Support\Api;
use Monokulo\PrestaShop\Tests\Support\Vectors;
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
		$this->assertArrayHasKey('send-test-webhook', Api::entry()['_templates']);
		$this->assertArrayNotHasKey('send-logs', Api::example('get', '/api/v1', '200', 'pk')['_templates'], 'only the secret key may send logs');
		$this->assertArrayNotHasKey('send-test-webhook', Api::example('get', '/api/v1', '200', 'pk')['_templates'], 'only the secret key may send a test');

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

	/**
	 * The order links the module follows: the payer's page, and its onion
	 * twin only from a Monokulo with an onion address. Both text/html pages
	 * Monokulo serves.
	 */
	public function test_an_order_links_the_pages_the_module_sends_people_to(): void {
		$links = Api::order('order_1')['_links'];
		$this->assertSame('/checkout/order_1', $links['mk:checkout']['href']);
		$this->assertSame(Api::ONION . '/checkout/order_1', $links['mk:onion-checkout']['href']);
		foreach (['mk:checkout', 'mk:onion-checkout'] as $rel) {
			$this->assertSame('text/html', $links[$rel]['type'], $rel);
		}
		$this->assertArrayNotHasKey('mk:onion-checkout', Api::order('order_1', onion: false)['_links'], 'no onion address, no onion checkout');
		$this->assertSame('/checkout/order_1', Api::order('order_1', onion: false)['_links']['mk:checkout']['href']);
		$this->assertArrayHasKey('text/html', Api::operation('get', '/checkout/{order_id}')['responses']['200']['content']);
	}

	/** The recordings tell one story: the order the secret key makes is the one it reads. */
	public function test_the_order_made_is_the_order_read(): void {
		foreach (['sk', 'sk-with-onion'] as $name) {
			$made = Api::example('post', '/api/v1/orders', '201', $name);
			$read = Api::example('get', '/api/v1/orders/{order_id}', '200', $name);
			$this->assertSame($made['order_id'], $read['order_id'], $name);
			$this->assertSame($made['_links'], $read['_links'], $name);
		}
	}

	/**
	 * The merchant's link to the order comes with its webhooks: every
	 * published delivery about the order links `mk:dashboard-order`, the dashboard page the
	 * API gives the secret key (and not the public key), absolute.
	 */
	public function test_the_merchants_link_comes_with_every_webhook(): void {
		$route = '#\Ahttps?://[^/]+/stores/[^/]+/orders/[^/]+\z#';
		foreach (Vectors::orderDeliveries() as $name => $delivery) {
			$this->assertMatchesRegularExpression($route, json_decode($delivery['body'], true)['_links']['mk:dashboard-order']['href'], $name);
		}
		$this->assertSame('text/html', Api::order('order_1')['_links']['mk:dashboard-order']['type']);
		$this->assertArrayHasKey('text/html', Api::operation('get', '/stores/{id}/orders/{order_id}')['responses']['200']['content']);
		$this->assertArrayNotHasKey('mk:dashboard-order', Api::example('post', '/api/v1/orders', '201', 'pk')['_links'], 'the merchant\'s page is for the secret key only');
	}

	/**
	 * Each problem the module tells apart is one Monokulo lists for its
	 * route, under its own status, with a recorded example, and the headers
	 * the module reads; no route falls back to a catch-all `default`.
	 */
	public function test_the_problems_the_module_tells_apart_are_listed_per_route(): void {
		$orders = Api::operation('post', '/api/v1/orders')['responses'];
		foreach (['invalid-amount' => 400, 'unauthorized' => 401, 'forbidden' => 403, 'idempotency-conflict' => 409, 'rate-limited' => 429, 'engine-unavailable' => 503, 'rates-unavailable' => 503] as $name => $status) {
			$problem = Api::recordedProblem('post', '/api/v1/orders', $name);
			$this->assertSame($status, $problem['status'], $name);
			$this->assertSame('https://docs.monokulo.com/problems/' . $name, $problem['type'], $name);
			$this->assertArrayHasKey($name, $orders[(string)$status]['content']['application/problem+json']['examples'], $name);
		}
		$this->assertArrayHasKey('Retry-After', $orders['503']['headers']);
		$this->assertArrayHasKey('Retry-After', $orders['429']['headers']);
		$this->assertIsInt(Api::recordedProblem('post', '/api/v1/orders', 'rates-unavailable')['retry_after']);
		$this->assertSame(401, Api::recordedProblem('get', '/api/v1', 'unauthorized')['status']);

		foreach ([['post', '/api/v1/orders'], ['get', '/api/v1'], ['post', '/api/v1/logs'], ['post', '/plugins/connect/{slug}/finish']] as [$method, $path]) {
			$this->assertArrayNotHasKey('default', Api::operation($method, $path)['responses'], "$method $path");
		}
	}

	/**
	 * Monokulo leaves a field with no value out instead of sending `null`:
	 * no order or store field the recordings show is `null`, and the fields
	 * that may have no value aren't required.
	 */
	public function test_a_field_with_no_value_is_left_out(): void {
		$order = Api::order('order_1');
		array_walk_recursive($order, function ($value, $key): void {
			$this->assertNotNull($value, (string)$key);
		});
		$required = Api::schema('Order')['required'] ?? [];
		foreach (['cancelled_at', 'amount', 'currency', 'merchant_order_id', 'description'] as $field) {
			$this->assertNotContains($field, $required, $field);
		}
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
		$this->assertArrayNotHasKey('onion_api', Api::finished(onion: false), 'no onion address, no onion entry');
		$this->assertArrayNotHasKey('mk:onion-api', Api::entry(onion: false)['_links']);
		$this->assertSame(Api::finished()['secret_token'], Api::finished(onion: false)['secret_token'], 'one story: the same store either way');
	}

	/**
	 * "Test connection" sends `{}` with the send-test-webhook form (the
	 * shop's own webhook), and reads the fields its answer has: the stand-in's
	 * answers are the recorded one, and one changed only where the schema
	 * says a test that wasn't delivered differs.
	 */
	public function test_the_test_webhook_form_takes_and_answers_what_the_module_uses(): void {
		$form = Api::entry()['_templates']['send-test-webhook'];
		$this->assertSame(['POST', '/api/v1/webhook-tests', 'application/json'], [$form['method'], $form['target'], $form['contentType']]);
		$this->assertSame([], Api::schema('WebhookTestsInput')['required'] ?? [], '{} is a whole request');
		$this->assertSame(['webhook_id'], array_column($form['properties'], 'name'));
		$this->assertEquals((object)[], (object)Api::operation('post', '/api/v1/webhook-tests')['requestBody']['content']['application/json']['example']);

		$answer = Api::schema('WebhookTests');
		$read = ['event_id', 'delivered', 'status', 'duration_ms', 'error', 'response', 'next_attempt_at'];
		$this->assertSame([], array_values(array_diff($read, $this->properties($answer))), 'every field the module reads is one Monokulo answers');
		$this->assertSame([], array_values(array_diff(['event_id', 'delivered', 'duration_ms'], $answer['required'])));
		foreach (['status', 'error', 'response', 'next_attempt_at'] as $optional) {
			$this->assertNotContains($optional, $answer['required'], $optional . ' is left out when there is none');
		}
		$this->assertSame([], array_values(array_diff(array_keys(Api::testNotDelivered()), $this->properties($answer))), 'the stand-in\'s undelivered test has the same shape');
		$this->assertTrue(Api::testDelivered()['delivered']);
		$this->assertArrayNotHasKey('error', Api::testDelivered(), 'delivered: no error');
		$this->assertArrayNotHasKey('next_attempt_at', Api::testDelivered(), 'delivered: not tried again');

		$responses = Api::operation('post', '/api/v1/webhook-tests')['responses'];
		foreach (['not-found' => 404, 'rate-limited' => 429] as $name => $status) {
			$this->assertSame($status, Api::recordedProblem('post', '/api/v1/webhook-tests', $name)['status'], $name);
		}
		$this->assertArrayHasKey('Retry-After', $responses['429']['headers']);
		$this->assertIsInt(Api::recordedProblem('post', '/api/v1/webhook-tests', 'rate-limited')['retry_after']);
		$this->assertArrayNotHasKey('default', $responses);
	}

	/** A test delivery names the store and the webhook, and no order: there's nothing to act on. */
	public function test_a_test_delivery_has_no_order(): void {
		$test = json_decode(Vectors::delivery('webhook.test')['body'], true);
		$this->assertSame('webhook.test', $test['event']);
		$this->assertSame('webhook.test', Vectors::delivery('webhook.test')['headers']['X-Monokulo-Event']);
		$this->assertArrayNotHasKey('order_id', $test);
		$this->assertArrayNotHasKey('status', $test);
		$this->assertMatchesRegularExpression('/\Awh_[0-9a-f]{32}\z/', $test['webhook']['id']);
		$this->assertArrayHasKey('mk:store', $test['_links']);
	}
}
