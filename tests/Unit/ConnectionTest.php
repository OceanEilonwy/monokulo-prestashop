<?php
namespace Monokulo\PrestaShop\Tests\Unit;

use Monokulo\PrestaShop\Connection;
use Monokulo\PrestaShop\Plugin;
use PHPUnit\Framework\TestCase;

final class ConnectionTest extends TestCase {
	private function connection(string $version = Plugin::CONNECTION_VERSION): Connection {
		return new Connection('shop.example', 'https://pay.example.com/', 'pk_a/b', 'sk_x', 'whsec_x', $version, 1);
	}

	public function test_where_orders_customers_and_logs_go(): void {
		$c = $this->connection();

		$this->assertSame('https://pay.example.com/pay/pk_a%2Fb/orders', $c->ordersUrl());
		$this->assertSame('https://pay.example.com/pay/pk_a%2Fb/orders/ord%201', $c->checkoutUrl('ord 1'));
		$this->assertSame('https://pay.example.com/pay/pk_a%2Fb/logs', $c->logsUrl());
		$this->assertSame('https://pay.example.com/dashboard/stores/st1/orders/o1', $c->dashboardOrderUrl('st1', 'o1'));
	}

	/** A connection an older plugin version made must be made again. */
	public function test_an_older_connection_needs_connecting_again(): void {
		$this->assertFalse($this->connection()->needsReconnect());
		$this->assertTrue($this->connection('0')->needsReconnect());
		$this->assertTrue($this->connection('')->needsReconnect());
	}
}
