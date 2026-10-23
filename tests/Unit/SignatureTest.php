<?php
namespace Monokulo\PrestaShop\Tests\Unit;

use Monokulo\PrestaShop\Signature;
use Monokulo\PrestaShop\Tests\Support\Vectors;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;

final class SignatureTest extends TestCase {
	/** Every delivery Monokulo publishes verifies with its secret at its time. */
	#[DataProviderExternal(Vectors::class, 'deliveryCases')]
	public function test_every_published_delivery_verifies(array $delivery): void {
		$this->assertTrue(Signature::verify(Vectors::secret(), $delivery['body'], $delivery['headers']['X-Monokulo-Signature'], Vectors::sentAt()));
	}

	/** Changed body, another webhook's secret, too old: all refused. */
	#[DataProviderExternal(Vectors::class, 'refusedCases')]
	public function test_every_published_forgery_is_refused(array $refuse): void {
		$this->assertFalse(Signature::verify(Vectors::secret(), $refuse['body'], $refuse['headers']['X-Monokulo-Signature'], Vectors::sentAt()));
	}

	/** Five minutes either side of the signing time is accepted; a second more is not. */
	public function test_five_minutes_either_way_is_the_limit(): void {
		$body = '{"event_id":"evt_1"}';
		$header = 't=1000000,v1=' . hash_hmac('sha256', '1000000.' . $body, 'whsec_x');

		$this->assertTrue(Signature::verify('whsec_x', $body, $header, 1000000 + 300));
		$this->assertTrue(Signature::verify('whsec_x', $body, $header, 1000000 - 300));
		$this->assertFalse(Signature::verify('whsec_x', $body, $header, 1000000 + 301));
		$this->assertFalse(Signature::verify('whsec_x', $body, $header, 1000000 - 301));
	}

	public function test_upper_case_hex_is_the_same_signature(): void {
		$body = '{}';
		$hex = hash_hmac('sha256', '5.' . $body, 'whsec_x');

		$this->assertTrue(Signature::verify('whsec_x', $body, 't=5,v1=' . strtoupper($hex), 5));
	}

	/** A store that never got a secret accepts nothing, even a signature made with the empty key. */
	public function test_no_secret_accepts_nothing(): void {
		$body = '{}';

		$this->assertFalse(Signature::verify('', $body, 't=5,v1=' . hash_hmac('sha256', '5.' . $body, ''), 5));
	}

	public static function malformedHeaders(): array {
		$hex = str_repeat('a', 64);

		return [
			'missing'         => [''],
			'no time'         => ['v1=' . $hex],
			'no signature'    => ['t=5'],
			'short hex'       => ['t=5,v1=' . str_repeat('a', 63)],
			'not hex'         => ['t=5,v1=' . str_repeat('g', 64)],
			'other scheme'    => ['t=5,v0=' . $hex],
			'extra part'      => ['t=5,v1=' . $hex . ',v2=' . $hex],
			'negative time'   => ['t=-5,v1=' . $hex],
			'reordered'       => ['v1=' . $hex . ',t=5'],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('malformedHeaders')]
	public function test_malformed_headers_are_refused(string $header): void {
		$this->assertFalse(Signature::verify('whsec_x', '{}', $header, 5));
	}
}
