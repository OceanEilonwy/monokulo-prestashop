<?php
namespace Monokulo\PrestaShop\Tests\Http;

use Monokulo\PrestaShop\Plugin;
use Monokulo\PrestaShop\Tests\Support\Browser;
use Monokulo\PrestaShop\Tests\Support\FakeMonokulo;
use Monokulo\PrestaShop\Tests\Support\Shop;
use Monokulo\PrestaShop\Tests\Support\Storefront;
use PHPUnit\Framework\TestCase;

/**
 * A guest's checkout through the storefront and Apache, with a stand-in
 * Monokulo: Monero offered at the payment step, "Place order" sending the
 * customer to pay, and the payment link that brings them back to it.
 */
final class CheckoutOverHttpTest extends TestCase {
	private FakeMonokulo $monokulo;

	protected function setUp(): void {
		$this->monokulo = new FakeMonokulo();
		Shop::exec("DELETE FROM ps_monokulo_connection");
		Shop::exec("INSERT INTO ps_monokulo_connection SET host = 'localhost:8093', endpoint = " . Shop::quote($this->monokulo->url) . ", public_key = 'pk_test', secret_token = 'sk_test', webhook_secret = 'whsec', connected_at = 1");
	}

	protected function tearDown(): void {
		$this->monokulo->stop();
		Shop::exec("DELETE FROM ps_monokulo_connection");
	}

	/** @return array{id_order: string, id_cart: string, secure_key: string, current_state: string, reference: string} */
	private function newestOrder(): array {
		return Shop::rows("SELECT id_order, id_cart, secure_key, current_state, reference FROM ps_orders WHERE module = 'monokulo' ORDER BY id_order DESC LIMIT 1")[0];
	}

	public function test_over_http_place_order_sends_the_customer_to_monokulo(): void {
		$this->monokulo->respond('POST', '/pay/pk_test/orders', 200, ['order_id' => 'ord_http']);
		$browser = new Browser();

		$payment = Storefront::toPayment($browser);
		$this->assertStringContainsString('Pay with Monero', $payment->text());
		$answer = Storefront::payWithMonero($browser, $payment);

		$checkout = $this->monokulo->url . '/pay/pk_test/orders/ord_http';
		$this->assertSame(302, $answer->status);
		$this->assertSame($checkout, $answer->location());
		$order = $this->newestOrder();
		$this->assertSame(Shop::setting('MONOKULO_OS_PENDING'), $order['current_state']);
		$body = json_decode($this->monokulo->requests()[0]['body'], true);
		$this->assertSame($order['reference'], $body['merchant_order_id']);
		$this->assertSame('Order ' . $order['reference'] . ': Mug The best is yet to come', $body['description']);
		$this->assertSame('prestashop/' . Plugin::VERSION, $this->monokulo->requests()[0]['headers']['monokulo-client']);

		// The payment link, from the confirmation email, goes to the same Monokulo order.
		$again = (new Browser())->get(Shop::URL . 'module/monokulo/pay?id_cart=' . $order['id_cart'] . '&key=' . $order['secure_key']);
		$this->assertSame(302, $again->status);
		$this->assertSame($checkout, $again->location());
		$wrong = (new Browser())->get(Shop::URL . 'module/monokulo/pay?id_cart=' . $order['id_cart'] . '&key=wrong');
		$this->assertNull($wrong->location());
		$this->assertStringContainsString('This payment link is not valid', $wrong->text());
	}

	/** Monokulo down: the order is placed, and the customer is told and can try again. */
	public function test_over_http_a_failed_payment_can_be_tried_again(): void {
		$this->monokulo->respond('POST', '/pay/pk_test/orders', 503, '{"error":"down"}');
		$browser = new Browser();

		$answer = Storefront::payWithMonero($browser, Storefront::toPayment($browser));

		$this->assertSame(200, $answer->status);
		$this->assertStringContainsString('The Monero payment could not be started. Please try again, or contact the store.', $answer->text());
		$this->assertMatchesRegularExpression('/id="monokulo-retry"[^>]*href="([^"]+)"/', $answer->body);
		preg_match('/id="monokulo-retry"[^>]*href="([^"]+)"/', $answer->body, $m);
		$this->monokulo->respond('POST', '/pay/pk_test/orders', 200, ['order_id' => 'ord_retry']);
		$this->assertSame($this->monokulo->url . '/pay/pk_test/orders/ord_retry', $browser->get(html_entity_decode($m[1]))->location());
	}

	public function test_over_http_monero_is_not_offered_before_the_shop_connects(): void {
		Shop::exec("DELETE FROM ps_monokulo_connection");
		$browser = new Browser();

		$this->assertNull(Storefront::moneroForm(Storefront::toPayment($browser)));
	}
}
