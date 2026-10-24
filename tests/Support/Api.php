<?php
namespace Monokulo\PrestaShop\Tests\Support;

/**
 * What Monokulo's API answers, from its OpenAPI document at the pinned
 * commit (tests/openapi.json, which scripts/openapi generates). Monokulo
 * records its examples against a test engine and checks each against the
 * document, so the stand-in Monokulo answers with real exchanges: the entry
 * and orders as the store's secret key sees them, the handshake, problems.
 *
 * Each answer is recorded from a Monokulo without an onion address (`sk`,
 * `no-key`, …) and from one with (`sk-with-onion`, …): `onion` picks which.
 * They tell one story: the order made is the one read and linked.
 */
final class Api {
	/** The onion address the recordings were made with. */
	public const ONION = 'http://2gzyxa5ihm7nsggfxnu52rck2vv4rvmdlkiu3zzui5du4xyclen53wid.onion';

	/** @var array<string, mixed>|null */
	private static ?array $document = null;

	/** @return array<string, mixed> the OpenAPI document */
	public static function document(): array {
		if (self::$document === null) {
			$file = __DIR__ . '/../openapi.json';
			preg_match('/^commit=(\S+)/m', (string)file_get_contents(__DIR__ . '/../vectors/PIN'), $pin);
			$from = trim((string)@file_get_contents(__DIR__ . '/../openapi.commit'));
			if (!is_file($file) || $from !== ($pin[1] ?? '')) {
				throw new \RuntimeException('tests/openapi.json is missing, or not from the commit in tests/vectors/PIN: run scripts/openapi');
			}
			self::$document = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
		}

		return self::$document;
	}

	/** @return array<string, mixed> the operation `method path` (`get`, `/api/v1`) */
	public static function operation(string $method, string $path): array {
		return self::document()['paths'][$path][$method];
	}

	/** @return array<string, mixed> the recorded example `name` of `method path`'s `status` answer */
	public static function example(string $method, string $path, string $status, string $name): array {
		$content = self::operation($method, $path)['responses'][$status]['content'];

		return reset($content)['examples'][$name]['value'];
	}

	/** @return array<string, mixed> a schema in the document's components, by name */
	public static function schema(string $name): array {
		return self::document()['components']['schemas'][$name];
	}

	/** @return array<string, mixed> the entry, `GET /api/v1`, as the store's secret key sees it */
	public static function entry(bool $onion = true): array {
		return self::example('get', '/api/v1', '200', $onion ? 'sk-with-onion' : 'sk');
	}

	/** @return array<string, mixed> order `orderId` as the create-order form answers the secret key */
	public static function order(string $orderId, bool $onion = true): array {
		$example = self::example('post', '/api/v1/orders', '201', $onion ? 'sk-with-onion' : 'sk');

		return json_decode(str_replace($example['order_id'], $orderId, (string)json_encode($example)), true);
	}

	/** @return array<string, mixed> the plugin handshake's answer (`POST /plugins/connect/{slug}/finish`) */
	public static function finished(bool $onion = true): array {
		return self::example('post', '/plugins/connect/{slug}/finish', '200', $onion ? 'no-key-with-onion' : 'no-key');
	}

	/** @return array<string, mixed> the send-test-webhook form's answer: the shop's webhook took the test */
	public static function testDelivered(bool $onion = true): array {
		return self::example('post', '/api/v1/webhook-tests', '200', $onion ? 'sk-with-onion' : 'sk');
	}

	/**
	 * The same answer for a test the shop's webhook didn't take (answered
	 * 500, tried again in a minute), which the document has no recording
	 * of: only the fields its schema says change.
	 *
	 * @return array<string, mixed>
	 */
	public static function testNotDelivered(): array {
		$answer = self::testDelivered();
		$answer['delivered'] = false;
		$answer['status'] = 500;
		$answer['error'] = 'answered 500';
		$answer['response'] = "HTTP/1.1 500 Internal Server Error\ncontent-type: text/plain; charset=utf-8\n\n500";
		$answer['next_attempt_at'] = 1760020452;

		return $answer;
	}

	/** @return array<string, mixed> the recorded problem `name` that `method path` answers with */
	public static function recordedProblem(string $method, string $path, string $name): array {
		foreach (self::operation($method, $path)['responses'] as $answer) {
			$examples = $answer['content']['application/problem+json']['examples'] ?? [];
			if (isset($examples[$name])) {
				return $examples[$name]['value'];
			}
		}

		throw new \RuntimeException("no recorded $name problem for $method $path");
	}

	/** @return array<string, mixed> a problem the document has no recording of, in the same shape */
	public static function problem(string $name, int $status, string $title, string $detail): array {
		return ['type' => 'https://docs.monokulo.com/problems/' . $name, 'title' => $title, 'status' => $status, 'detail' => $detail];
	}

	/**
	 * `body` as a Monokulo served under `prefix` (`/monokulo`) answers it:
	 * every path it links or posts to starts with the prefix. Onion links
	 * and links elsewhere are absolute, and stay as they are.
	 *
	 * @param array<string, mixed> $body
	 *
	 * @return array<string, mixed>
	 */
	public static function under(string $prefix, array $body): array {
		array_walk_recursive($body, static function (&$value, $key) use ($prefix): void {
			if (in_array($key, ['href', 'target', 'api'], true) && is_string($value) && str_starts_with($value, '/') && !str_starts_with($value, '//')) {
				$value = $prefix . $value;
			}
		});

		return $body;
	}
}
