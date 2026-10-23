<?php
namespace Monokulo\PrestaShop\Tests\Unit;

use Monokulo\PrestaShop\ConnectLink;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConnectLinkTest extends TestCase {
	public function test_the_link_names_the_plugin_and_carries_the_request(): void {
		$link = ConnectLink::start('https://pay.example.com/', 'https://shop.example/', 'https://shop.example/module/monokulo/connect', 'n1');

		$this->assertStringStartsWith('https://pay.example.com/plugins/connect/prestashop?', $link);
		parse_str((string)parse_url($link, PHP_URL_QUERY), $query);
		$this->assertSame([
			'site_url'   => 'https://shop.example/',
			'return_url' => 'https://shop.example/module/monokulo/connect',
			'nonce'      => 'n1',
			'name'       => 'PrestaShop',
		], $query);
	}

	public static function addresses(): array {
		return [
			'https'               => ['https://pay.example.com', 'https://pay.example.com'],
			'trailing slash'      => ['https://pay.example.com/', 'https://pay.example.com'],
			'spaces'              => ['  http://localhost:8081  ', 'http://localhost:8081'],
			'a path'              => ['https://example.com/monokulo/', 'https://example.com/monokulo'],
			'no scheme'           => ['pay.example.com', null],
			'ftp'                 => ['ftp://pay.example.com', null],
			'credentials'         => ['https://me:pw@pay.example.com', null],
			'a query'             => ['https://pay.example.com/?x=1', null],
			'empty'               => ['', null],
			'javascript'          => ['javascript:alert(1)', null],
		];
	}

	#[DataProvider('addresses')]
	public function test_the_monokulo_address_must_be_http(string $typed, ?string $expected): void {
		$this->assertSame($expected, ConnectLink::address($typed));
	}

	public function test_a_shop_host_is_lower_case_with_its_port(): void {
		$this->assertSame('shop.example', ConnectLink::host('https://Shop.Example/'));
		$this->assertSame('localhost:8090', ConnectLink::host('http://localhost:8090/'));
		$this->assertSame('shop.example', ConnectLink::host('https://shop.example/store/'));
		$this->assertSame('', ConnectLink::host('not a url'));
	}
}
