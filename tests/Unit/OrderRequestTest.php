<?php
namespace Monokulo\PrestaShop\Tests\Unit;

use Monokulo\PrestaShop\OrderRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OrderRequestTest extends TestCase {
	/** The order's reference is the merchant's order id; the description names it too. */
	public function test_the_body_monokulo_gets(): void {
		$body = OrderRequest::body(1, 1042, 'XKBKNABJK', 12.5, 'eur', 2, ['Blue mug', 'Tea', 'Spoon'], 'Order XKBKNABJK', ' + %d more');

		$this->assertSame([
			'amount'                 => '12.5',
			'currency'               => 'EUR',
			'merchant_order_id'      => 'XKBKNABJK',
			'show_merchant_order_id' => false,
			'description'            => 'Order XKBKNABJK: Blue mug + 2 more',
			'idempotency_key'        => 'ps:1:1042:12.5:EUR',
		], $body);
	}

	/** The same order and total retried is the same key; a changed total or another shop is not. */
	public function test_the_idempotency_key_follows_shop_order_and_total(): void {
		$key = static fn(int $shop, int $order, float $total): string => OrderRequest::body($shop, $order, 'REF', $total, 'EUR', 2, [], 'x', '%d')['idempotency_key'];

		$this->assertSame($key(1, 7, 10.0), $key(1, 7, 10.0));
		$this->assertNotSame($key(1, 7, 10.0), $key(1, 7, 10.5));
		$this->assertNotSame($key(1, 7, 10.0), $key(2, 7, 10.0));
		$this->assertLessThanOrEqual(100, strlen(OrderRequest::body(4294967295, 4294967295, 'REF', 99999999.99, 'XMR', 12, [], 'x', '%d')['idempotency_key']));
	}

	public static function amounts(): array {
		return [
			'trailing zero trimmed'  => [12.5, 'EUR', 2, '12.5'],
			'whole'                  => [12.0, 'EUR', 2, '12'],
			'two decimals'           => [12.34, 'EUR', 2, '12.34'],
			'rounded'                => [12.345, 'USD', 2, '12.35'],
			'rounded up to whole'    => [12.999, 'USD', 2, '13'],
			'no decimals currency'   => [1000.0, 'JPY', 0, '1000'],
			'JPY rounds'             => [1000.6, 'JPY', 0, '1001'],
			'three decimals'         => [1.2345, 'KWD', 3, '1.235'],
			'XMR keeps its places'   => [0.123456789012, 'XMR', 12, '0.123456789012'],
			'XMR capped at 12'       => [1.5, 'xmr', 15, '1.5'],
			'never more than 12'     => [0.1234567890123, 'BTC', 14, '0.123456789012'],
			'no thousands separator' => [1234567.0, 'EUR', 2, '1234567'],
			'zero'                   => [0.0, 'EUR', 2, '0'],
		];
	}

	/**
	 * In the currency's own decimals, trailing zeros trimmed: never padded
	 * past what Monokulo takes for a fiat currency (2).
	 */
	#[DataProvider('amounts')]
	public function test_amounts_are_plain_decimals(float $total, string $currency, int $decimals, string $expected): void {
		$this->assertSame($expected, OrderRequest::amount($total, $currency, $decimals));
	}

	public function test_one_item_has_no_more(): void {
		$this->assertSame('Order AAA: Mug', OrderRequest::description('Order AAA', ['Mug'], ' + %d more'));
		$this->assertSame('Order AAA', OrderRequest::description('Order AAA', [], ' + %d more'));
	}

	public function test_the_description_is_one_line(): void {
		$this->assertSame('Order AAA: Blue mug, large', OrderRequest::description('Order AAA', ["Blue\tmug,\r\n large"], ' + %d more'));
	}

	public function test_a_long_description_is_cut_at_140_characters(): void {
		$description = OrderRequest::description('Order AAA', [str_repeat('é', 200)], ' + %d more');

		$this->assertSame(140, mb_strlen($description, 'UTF-8'));
		$this->assertStringEndsWith('…', $description);
		$this->assertTrue(mb_check_encoding($description, 'UTF-8'));
	}
}
