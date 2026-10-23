<?php
namespace Monokulo\PrestaShop\Tests\Unit;

use Monokulo\PrestaShop\Url;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Monokulo's links are resolved against the URL they came from, never built. */
final class UrlTest extends TestCase {
	public static function links(): array {
		return [
			'a path on the host'  => ['https://m.example/api/v1', '/checkout/order_1', 'https://m.example/checkout/order_1'],
			'keeps the port'      => ['http://127.0.0.1:8081/api/v1', '/api/v1/orders', 'http://127.0.0.1:8081/api/v1/orders'],
			'an onion stays one'  => ['http://abc.onion/api/v1', '/checkout/o', 'http://abc.onion/checkout/o'],
			'already absolute'    => ['https://m.example/api/v1', 'https://other.example/x', 'https://other.example/x'],
			'scheme relative'     => ['https://m.example/api/v1', '//other.example/x', 'https://other.example/x'],
			'relative to a file'  => ['https://m.example/api/v1', 'orders', 'https://m.example/api/orders'],
			'relative to a folder' => ['https://m.example/api/v1/', 'orders', 'https://m.example/api/v1/orders'],
			'under a path'        => ['https://example.com/monokulo/plugins/connect/prestashop/finish', '/monokulo/api/v1', 'https://example.com/monokulo/api/v1'],
			'a form under a path' => ['https://example.com/monokulo/api/v1', '/monokulo/api/v1/orders', 'https://example.com/monokulo/api/v1/orders'],
		];
	}

	#[DataProvider('links')]
	public function test_links_resolve_against_where_they_came_from(string $base, string $ref, string $expected): void {
		$this->assertSame($expected, Url::resolve($base, $ref));
	}
}
