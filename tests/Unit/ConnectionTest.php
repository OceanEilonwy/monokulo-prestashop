<?php
namespace Monokulo\PrestaShop\Tests\Unit;

use Monokulo\PrestaShop\Connection;
use Monokulo\PrestaShop\Url;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConnectionTest extends TestCase {
	private function connection(): Connection {
		return new Connection('shop.example', 'https://pay.example.com:8443/api/v1', 'pk_x', 'sk_x', 'whsec_x', 1);
	}

	/** The one API address not linked from the entry, and the merchant's own page for an order. */
	public function test_where_logs_and_the_merchant_go(): void {
		$c = $this->connection();

		$this->assertSame('https://pay.example.com:8443/api/v1/logs', $c->logsUrl());
		$this->assertSame('https://pay.example.com:8443/stores/st%201/orders/o1', $c->dashboardOrderUrl('st 1', 'o1'));
	}

	public static function links(): array {
		return [
			'a path on the host'  => ['https://m.example/api/v1', '/checkout/order_1', 'https://m.example/checkout/order_1'],
			'keeps the port'      => ['http://127.0.0.1:8081/api/v1', '/api/v1/orders', 'http://127.0.0.1:8081/api/v1/orders'],
			'an onion stays one'  => ['http://abc.onion/api/v1', '/checkout/o', 'http://abc.onion/checkout/o'],
			'already absolute'    => ['https://m.example/api/v1', 'https://other.example/x', 'https://other.example/x'],
			'scheme relative'     => ['https://m.example/api/v1', '//other.example/x', 'https://other.example/x'],
			'relative to a file'  => ['https://m.example/api/v1', 'orders', 'https://m.example/api/orders'],
			'relative to a folder' => ['https://m.example/api/v1/', 'orders', 'https://m.example/api/v1/orders'],
		];
	}

	#[DataProvider('links')]
	public function test_links_resolve_against_where_they_came_from(string $base, string $ref, string $expected): void {
		$this->assertSame($expected, Url::resolve($base, $ref));
	}
}
