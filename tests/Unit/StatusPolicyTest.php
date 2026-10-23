<?php
namespace Monokulo\PrestaShop\Tests\Unit;

use Monokulo\PrestaShop\Decision;
use Monokulo\PrestaShop\StatusPolicy;
use Monokulo\PrestaShop\Tests\Support\Vectors;
use Monokulo\PrestaShop\WebhookEvent;
use PHPUnit\Framework\TestCase;

/**
 * The order-status table the README documents, checked against every event
 * in Monokulo's published vectors.
 */
final class StatusPolicyTest extends TestCase {
	private const TABLE = [
		// event                        status                     note                          customer told
		'order.unconfirmed'           => [Decision::STATUS_WAITING, 'note_waiting', false],
		'order.confirming'            => [Decision::STATUS_WAITING, 'note_waiting', false],
		'order.partial'               => [Decision::STATUS_PARTIAL, 'note_partial', false],
		'order.paid'                  => [Decision::STATUS_PAID, 'note_paid', true],
		'order.overpaid'              => [Decision::STATUS_PAID, 'note_overpaid', true],
		'order.expired'               => [Decision::STATUS_EXPIRED, 'note_expired', false],
		'order.cancelled'             => [Decision::STATUS_EXPIRED, 'note_cancelled', false],
		'order.double_spend_detected' => [null, 'note_double_spend_detected', false],
		'order.double_spend_reversed' => [null, 'note_double_spend_reversed', false],
	];

	public function test_every_published_event_follows_the_table(): void {
		$seen = [];
		foreach (Vectors::deliveries() as $name => $delivery) {
			$event = WebhookEvent::parse($delivery['body']);
			$this->assertArrayHasKey($event->event, self::TABLE, "Monokulo publishes $name, which the table doesn't cover");
			[$status, $note, $notify] = self::TABLE[$event->event];
			$decision = StatusPolicy::decide($event);

			$this->assertSame($status, $decision->status, $name);
			$this->assertSame($note, $decision->note, $name);
			$this->assertSame($notify, $decision->notify, $name);
			$this->assertSame($status === Decision::STATUS_PAID, $decision->paid, $name);
			$seen[$event->event] = true;
		}

		$this->assertSame(array_keys(self::TABLE), array_keys(array_intersect_key(self::TABLE, $seen)), 'every row of the table is in the vectors');
	}

	/** A newer Monokulo's event is accepted and changes nothing. */
	public function test_an_unknown_event_does_nothing(): void {
		$this->assertNull(StatusPolicy::decide(WebhookEvent::parse('{"event":"order.refunded","event_id":"evt_9","order_id":"o1"}')));
	}

	/** There is no order.pending webhook: the order starts as "order placed" when the customer is sent to pay. */
	public function test_there_is_no_pending_event(): void {
		foreach (Vectors::deliveries() as $delivery) {
			$this->assertNotSame('order.pending', WebhookEvent::parse($delivery['body'])->event);
		}
	}
}
