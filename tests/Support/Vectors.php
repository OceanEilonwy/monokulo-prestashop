<?php
namespace Monokulo\PrestaShop\Tests\Support;

/**
 * Monokulo's published webhook test vectors (tests/vectors), pinned to the
 * monokulo commit in tests/vectors/PIN: one signed delivery per event, and
 * deliveries a receiver must refuse. Monokulo's own tests build the file
 * from the code that sends real webhooks, so passing on it means handling
 * what Monokulo sends.
 */
final class Vectors {
	/** @var array<string, mixed>|null */
	private static ?array $data = null;

	/** @return array<string, mixed> */
	private static function data(): array {
		if (self::$data === null) {
			$data = json_decode((string)file_get_contents(__DIR__ . '/../vectors/webhook-vectors.json'), true, 512, JSON_THROW_ON_ERROR);
			self::$data = $data;
		}

		return self::$data;
	}

	public static function secret(): string {
		return self::data()['signing_secret'];
	}

	/** The time every vector was signed at: check them with the clock here. */
	public static function sentAt(): int {
		return (int)self::data()['sent_at'];
	}

	/** @return array<string, array{name: string, body: string, headers: array<string, string>}> keyed by name */
	public static function deliveries(): array {
		$out = [];
		foreach (self::data()['deliveries'] as $delivery) {
			$out[$delivery['name']] = $delivery;
		}

		return $out;
	}

	/**
	 * The deliveries about the order (all but `webhook.test`, which is about
	 * no order), keyed by name.
	 *
	 * @return array<string, array{name: string, body: string, headers: array<string, string>}>
	 */
	public static function orderDeliveries(): array {
		return array_filter(self::deliveries(), static fn(array $delivery): bool => isset(json_decode($delivery['body'], true)['order_id']));
	}

	/** @return array<string, array{body: string, headers: array<string, string>, why: string}> keyed by why */
	public static function refused(): array {
		$out = [];
		foreach (self::data()['refuse'] as $refuse) {
			$out[$refuse['why']] = $refuse;
		}

		return $out;
	}

	/** @return array{name: string, body: string, headers: array<string, string>} */
	public static function delivery(string $name): array {
		return self::deliveries()[$name];
	}

	/** The Monokulo order every vector is about. */
	public static function orderId(): string {
		return json_decode(self::delivery('order.paid')['body'], true)['order_id'];
	}

	/** PHPUnit data provider rows: one per delivery. */
	public static function deliveryCases(): array {
		$cases = [];
		foreach (self::deliveries() as $name => $delivery) {
			$cases[$name] = [$delivery];
		}

		return $cases;
	}

	/** PHPUnit data provider rows: one per delivery to refuse. */
	public static function refusedCases(): array {
		$cases = [];
		foreach (self::refused() as $why => $refuse) {
			$cases[$why] = [$refuse];
		}

		return $cases;
	}
}
