<?php
namespace Monokulo\PrestaShop\Tests\Unit;

use Monokulo\PrestaShop\Tests\Support\Vectors;
use Monokulo\PrestaShop\WebhookEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;

final class WebhookEventTest extends TestCase {
	#[DataProviderExternal(Vectors::class, 'deliveryCases')]
	public function test_every_published_delivery_parses(array $delivery): void {
		$event = WebhookEvent::parse($delivery['body']);

		$this->assertNotNull($event);
		$this->assertSame($delivery['headers']['X-Monokulo-Event'], $event->event);
		$this->assertSame($delivery['headers']['X-Monokulo-Event-Id'], $event->eventId);
		$this->assertSame(Vectors::orderId(), $event->orderId);
	}

	public function test_the_double_spend_events_carry_their_transaction(): void {
		foreach (['order.double_spend_detected', 'order.double_spend_reversed'] as $name) {
			$this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', (string)WebhookEvent::parse(Vectors::delivery($name)['body'])->txid, $name);
		}
		$this->assertNull(WebhookEvent::parse(Vectors::delivery('order.paid')['body'])->txid);
	}

	/** Every delivery links the order's page on the merchant's dashboard, absolute. */
	#[DataProviderExternal(Vectors::class, 'deliveryCases')]
	public function test_every_published_delivery_links_the_dashboard(array $delivery): void {
		$this->assertMatchesRegularExpression('#\Ahttps://[^/]+/stores/[^/]+/orders/' . Vectors::orderId() . '\z#', (string)WebhookEvent::parse($delivery['body'])->dashboard);
	}

	public static function notEnvelopes(): array {
		return [
			'not JSON'          => ['nope'],
			'a list'            => ['[1,2]'],
			'no event'          => ['{"event_id":"evt_1","order_id":"o1"}'],
			'no event id'       => ['{"event":"order.paid","order_id":"o1"}'],
			'no order id'       => ['{"event":"order.paid","event_id":"evt_1"}'],
			'empty event id'    => ['{"event":"order.paid","event_id":"","order_id":"o1"}'],
			'numeric order id'  => ['{"event":"order.paid","event_id":"evt_1","order_id":5}'],
		];
	}

	#[DataProvider('notEnvelopes')]
	public function test_anything_else_is_not_an_event(string $body): void {
		$this->assertNull(WebhookEvent::parse($body));
	}

	/** Fields Monokulo adds later, or optional ones left out, don't stop it. */
	public function test_unknown_and_missing_optional_fields_are_fine(): void {
		$event = WebhookEvent::parse('{"event":"order.refunded","event_id":"evt_9","order_id":"o1","new_field":{"x":1}}');

		$this->assertSame('order.refunded', $event->event);
		$this->assertNull($event->txid);
		$this->assertNull($event->dashboard);
	}
}
