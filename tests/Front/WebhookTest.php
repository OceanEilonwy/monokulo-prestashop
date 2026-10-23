<?php
namespace Monokulo\PrestaShop\Tests\Front;

use Monokulo\PrestaShop\Logger;
use Monokulo\PrestaShop\Tests\Support\ShopTestCase;
use Monokulo\PrestaShop\Tests\Support\Vectors;
use Monokulo\PrestaShop\Trace;
use Monokulo\PrestaShop\WebhookReceiver;

/**
 * Monokulo's published webhook deliveries, byte for byte, through the
 * receiver and the module's order updater, against real PrestaShop orders,
 * their order history and their messages. The clock is the vectors'
 * `sent_at`, so their own signatures check out.
 */
final class WebhookTest extends ShopTestCase {
	private \Order $order;

	/** @var list<string> */
	private array $log = [];

	protected function setUp(): void {
		parent::setUp();
		$this->connect();
		// As "Pay with Monero" leaves it: placed, mapped to the Monokulo order the vectors are about.
		$this->order = $this->placedOrder();
		$this->repository()->recordOrder(Vectors::orderId(), (int)$this->order->id, 1, self::HOST, '14.28', 'GBP', 1760000000);
	}

	/** The order's page on the merchant's dashboard, as the delivery `name` links it. */
	private function dashboard(string $name): string {
		return json_decode(Vectors::delivery($name)['body'], true)['_links']['mk:dashboard-order']['href'];
	}

	/** Delivers `body` signed with `signature` as of the vectors' clock; returns the HTTP status. */
	private function deliver(string $body, string $signature, ?int $now = null): int {
		$module = $this->module();
		$logger = new Logger(function (string $line): void {
			$this->log[] = $line;
		}, new Trace());

		return (new WebhookReceiver($module->repository(), $module->orders(), $logger))->handle($module->connection(), $body, $signature, $now ?? Vectors::sentAt());
	}

	private function deliverVector(string $name): int {
		$delivery = Vectors::delivery($name);

		return $this->deliver($delivery['body'], $delivery['headers']['X-Monokulo-Signature']);
	}

	private function currentStatus(): int {
		\Cache::clean('*');

		return $this->orderStatus((int)$this->order->id);
	}

	private function lastNote(): string {
		$notes = $this->notes((int)$this->order->id);

		return (string)end($notes);
	}

	private function awaiting(): int {
		return $this->module()->orderStatus('pending');
	}

	public function test_a_payment_seen_waits_in_the_waiting_status(): void {
		$bankWire = (int)\Configuration::get('PS_OS_BANKWIRE'); // unpaid, and not the default, so the change shows
		$this->setting('MONOKULO_OS_WAITING', (string)$bankWire);

		$this->assertSame(200, $this->deliverVector('order.unconfirmed'));
		$this->assertSame($bankWire, $this->currentStatus());
		$this->assertStringContainsString('waiting for confirmations', $this->lastNote());

		$this->assertSame(200, $this->deliverVector('order.confirming'));
		$this->assertSame($bankWire, $this->currentStatus());
		$this->assertSame([$this->awaiting(), $bankWire], $this->statusHistory((int)$this->order->id), 'a status already reached is not entered again');
	}

	public function test_part_paid_goes_to_the_partial_status_with_a_note(): void {
		$bankWire = (int)\Configuration::get('PS_OS_BANKWIRE');
		$this->setting('MONOKULO_OS_PARTIAL', (string)$bankWire);

		$this->assertSame(200, $this->deliverVector('order.partial'));
		$this->assertSame($bankWire, $this->currentStatus());
		$this->assertStringContainsString('part of the amount due has arrived', $this->lastNote());
	}

	/**
	 * Paid: Payment accepted, which records the payment and the invoice
	 * (and whose email tells the customer), and the merchant's note with the
	 * dashboard link.
	 */
	public function test_paid_moves_the_order_to_payment_accepted(): void {
		$this->assertSame(200, $this->deliverVector('order.paid'));

		$this->assertSame((int)\Configuration::get('PS_OS_PAYMENT'), $this->currentStatus());
		$order = new \Order((int)$this->order->id);
		$this->assertGreaterThan(0, $order->hasBeenPaid());
		$payments = $order->getOrderPaymentCollection();
		$this->assertCount(1, $payments);
		$this->assertEqualsWithDelta((float)$order->total_paid, (float)$payments->getFirst()->amount, 0.001);
		$this->assertStringContainsString('Monokulo: paid in full.', $this->lastNote());
		$this->assertStringEndsWith(' In Monokulo: ' . $this->dashboard('order.paid'), $this->lastNote());
		$this->assertStringStartsWith('https://', $this->dashboard('order.paid'), 'absolute, as every webhook link is');
	}

	/** Every event links the merchant to the order in Monokulo, from that delivery's own links. */
	public function test_every_note_links_the_order_in_monokulo(): void {
		foreach (Vectors::deliveries() as $name => $delivery) {
			$this->deliver($delivery['body'], $delivery['headers']['X-Monokulo-Signature']);
			$this->assertStringEndsWith(' In Monokulo: ' . $this->dashboard($name), $this->lastNote(), $name);
		}
	}

	/** A delivery that doesn't link the dashboard (none does today) gets a note without the link. */
	public function test_a_delivery_without_a_dashboard_link_gets_a_note_without_one(): void {
		$event = json_decode(Vectors::delivery('order.paid')['body'], true);
		unset($event['_links']['mk:dashboard-order']);
		$body = (string)json_encode($event);
		$t = Vectors::sentAt();

		$this->assertSame(200, $this->deliver($body, 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $body, Vectors::secret())));

		$this->assertSame('Monokulo: paid in full.', $this->lastNote());
	}

	public function test_paid_in_xmr_is_paid_too(): void {
		$this->assertSame(200, $this->deliverVector('order.paid priced in XMR'));
		$this->assertSame((int)\Configuration::get('PS_OS_PAYMENT'), $this->currentStatus());
	}

	public function test_overpaid_is_paid_with_a_note_about_the_excess(): void {
		$this->assertSame(200, $this->deliverVector('order.overpaid'));
		$this->assertSame((int)\Configuration::get('PS_OS_PAYMENT'), $this->currentStatus());
		$this->assertStringContainsString('more than the amount due', $this->lastNote());
	}

	/** Expired: Canceled, which also puts the stock back. */
	public function test_expired_goes_to_canceled(): void {
		$stock = (int)\StockAvailable::getQuantityAvailableByProduct(self::MUG);

		$this->assertSame(200, $this->deliverVector('order.expired'));

		$this->assertSame((int)\Configuration::get('PS_OS_CANCELED'), $this->currentStatus());
		$this->assertStringContainsString('payment window closed', $this->lastNote());
		\Cache::clean('*');
		$this->assertSame($stock + 1, (int)\StockAvailable::getQuantityAvailableByProduct(self::MUG), 'the mug is back in stock');
	}

	/**
	 * Cancelled in Monokulo (only its POS cancels, so a shop order shouldn't
	 * be): as expired, the merchant's "Expired" status, with its own note.
	 */
	public function test_cancelled_goes_where_expired_goes(): void {
		$bankWire = (int)\Configuration::get('PS_OS_BANKWIRE');
		$this->setting('MONOKULO_OS_EXPIRED', (string)$bankWire);

		$this->assertSame(200, $this->deliverVector('order.cancelled'));

		$this->assertSame($bankWire, $this->currentStatus());
		$this->assertStringStartsWith('Monokulo: the order was cancelled in Monokulo, so it takes no payment any more.', $this->lastNote());
	}

	/** Cancelled, then paid anyway (money sent to it is still recorded): paid. */
	public function test_a_cancelled_order_paid_late_is_paid(): void {
		$this->deliverVector('order.cancelled');

		$this->assertSame(200, $this->deliverVector('order.paid from a Monokulo with an onion address'));

		$this->assertSame((int)\Configuration::get('PS_OS_PAYMENT'), $this->currentStatus());
		$this->assertSame(1, $this->payments());
	}

	/** A double spend only adds a note: the status change, if any, comes as its own event. */
	public function test_double_spend_alerts_are_notes_only(): void {
		$this->deliverVector('order.paid');
		$this->assertSame(200, $this->deliverVector('order.double_spend_detected'));
		$this->assertSame((int)\Configuration::get('PS_OS_PAYMENT'), $this->currentStatus());
		$this->assertStringContainsString('FRAUD ALERT', $this->lastNote());
		$this->assertMatchesRegularExpression('/transaction [0-9a-f]{64}/', $this->lastNote());

		$this->assertSame(200, $this->deliverVector('order.double_spend_reversed'));
		$this->assertSame((int)\Configuration::get('PS_OS_PAYMENT'), $this->currentStatus());
		$this->assertStringContainsString('was wrong and is withdrawn', $this->lastNote());
	}

	/**
	 * Once paid, a later event that isn't a payment (the order expired, or
	 * went back to part paid after a double spend) leaves the order paid:
	 * PrestaShop has its payment and invoice, and the merchant decides.
	 */
	public function test_a_paid_order_is_not_moved_back(): void {
		$this->deliverVector('order.paid');

		$this->assertSame(200, $this->deliverVector('order.partial'));
		$this->assertSame(200, $this->deliverVector('order.expired'));

		$this->assertSame((int)\Configuration::get('PS_OS_PAYMENT'), $this->currentStatus());
		$this->assertStringContainsString('payment window closed', $this->lastNote());
		$this->assertStringContainsString('The order was already paid, so its status is left as it is', $this->lastNote());

		$this->assertSame(200, $this->deliverVector('order.cancelled'));
		$this->assertSame((int)\Configuration::get('PS_OS_PAYMENT'), $this->currentStatus());
		$this->assertStringContainsString('was cancelled in Monokulo', $this->lastNote());
		$this->assertStringContainsString('The order was already paid, so its status is left as it is', $this->lastNote());
	}

	/** Every published delivery, in order, on one order: each applies once, with one note each. */
	public function test_every_published_delivery_is_accepted(): void {
		foreach (Vectors::deliveries() as $name => $delivery) {
			$this->assertSame(200, $this->deliver($delivery['body'], $delivery['headers']['X-Monokulo-Signature']), $name);
		}
		$this->assertCount(count(Vectors::deliveries()), $this->notes((int)$this->order->id));
		$this->assertSame((int)\Configuration::get('PS_OS_PAYMENT'), $this->currentStatus());
	}

	/** A delivery whose answer was lost is sent again; the second changes nothing. */
	public function test_a_repeated_delivery_changes_nothing(): void {
		$this->assertSame(200, $this->deliverVector('order.paid'));
		$notes = $this->notes((int)$this->order->id);
		$history = $this->statusHistory((int)$this->order->id);

		$this->assertSame(200, $this->deliverVector('order.paid'));
		$this->assertSame($notes, $this->notes((int)$this->order->id));
		$this->assertSame($history, $this->statusHistory((int)$this->order->id));
	}

	public function test_every_published_forgery_is_refused_and_changes_nothing(): void {
		foreach (Vectors::refused() as $why => $refuse) {
			$this->assertSame(401, $this->deliver($refuse['body'], $refuse['headers']['X-Monokulo-Signature']), $why);
		}
		$this->assertSame($this->awaiting(), $this->currentStatus());
		$this->assertSame([], $this->notes((int)$this->order->id));
	}

	public function test_a_genuine_delivery_arriving_too_late_is_refused(): void {
		$delivery = Vectors::delivery('order.paid');

		$this->assertSame(401, $this->deliver($delivery['body'], $delivery['headers']['X-Monokulo-Signature'], Vectors::sentAt() + 301));
	}

	public function test_a_shop_that_never_connected_refuses_everything(): void {
		$this->db()->execute('DELETE FROM `' . _DB_PREFIX_ . 'monokulo_connection`');

		$this->assertSame(401, $this->deliverVector('order.paid'));
	}

	public function test_signed_but_not_an_envelope_is_a_bad_request(): void {
		$body = '{"hello":"world"}';
		$t = Vectors::sentAt();

		$this->assertSame(400, $this->deliver($body, 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $body, Vectors::secret())));
	}

	/** Retried, in case the order is still being saved. */
	public function test_an_order_this_shop_never_made_is_not_found(): void {
		$this->db()->execute('DELETE FROM `' . _DB_PREFIX_ . 'monokulo_order`');

		$this->assertSame(404, $this->deliverVector('order.paid'));
	}

	public function test_an_event_this_version_does_not_know_is_accepted_and_ignored(): void {
		$body = json_encode(['api_version' => 2, 'event_id' => 'evt_new', 'event' => 'order.refunded', 'created_at' => 1, 'order_id' => Vectors::orderId()]);
		$t = Vectors::sentAt();

		$this->assertSame(200, $this->deliver($body, 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $body, Vectors::secret())));
		$this->assertSame([], $this->notes((int)$this->order->id));
		$this->assertStringContainsString('unknown event "order.refunded"', implode("\n", $this->log));
	}

	/**
	 * Delivers the vector `name` as if it were about Monokulo order
	 * `orderId`: the same event, its own id, signed with the shop's secret.
	 */
	private function deliverFor(string $name, string $orderId): int {
		$event = json_decode(Vectors::delivery($name)['body'], true);
		$event['order_id'] = $orderId;
		$event['event_id'] .= '_' . $orderId;
		$body = (string)json_encode($event);
		$t = Vectors::sentAt();

		return $this->deliver($body, 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $body, Vectors::secret()));
	}

	/** The order's total changed after its first payment request (the vectors' order): a newer one is the current one. */
	private function newerRequest(): string {
		$this->repository()->recordOrder('order_newer', (int)$this->order->id, 1, self::HOST, '20.00', 'GBP', 1760000001);

		return 'order_newer';
	}

	private function payments(): int {
		return count((new \Order((int)$this->order->id))->getOrderPaymentCollection());
	}

	/** The money arrived, whichever of the order's payment requests it went to: paid. */
	public function test_paying_an_earlier_request_marks_the_order_paid(): void {
		$this->newerRequest();

		$this->assertSame(200, $this->deliverVector('order.paid'), 'an earlier Monokulo order is still recognised');

		$this->assertSame((int)\Configuration::get('PS_OS_PAYMENT'), $this->currentStatus());
		$this->assertSame(1, $this->payments());
		$this->assertStringContainsString('Monokulo: paid in full.', $this->lastNote());
		$this->assertStringContainsString('earlier payment request for this order', $this->lastNote());
		$this->assertNotNull($this->rows('SELECT `paid_at` FROM `' . _DB_PREFIX_ . "monokulo_order` WHERE `monokulo_order_id` = '" . Vectors::orderId() . "'")[0]['paid_at']);
	}

	/** Anything but a payment from a request that isn't the current one: a note, and the status stays. */
	public function test_other_events_of_an_earlier_request_are_notes_only(): void {
		$this->newerRequest();
		$stock = (int)\StockAvailable::getQuantityAvailableByProduct(self::MUG);

		foreach (['order.unconfirmed', 'order.partial', 'order.expired', 'order.cancelled'] as $name) {
			$this->assertSame(200, $this->deliverVector($name), $name);
			$this->assertSame($this->awaiting(), $this->currentStatus(), $name);
			$this->assertStringContainsString('earlier payment request for this order', $this->lastNote(), $name);
		}
		$this->assertStringContainsString('was cancelled in Monokulo', $this->lastNote());
		$this->assertCount(4, $this->notes((int)$this->order->id));
		\Cache::clean('*');
		$this->assertSame($stock, (int)\StockAvailable::getQuantityAvailableByProduct(self::MUG), 'not cancelled, so no stock back');
	}

	/** The current request still moves the order as before. */
	public function test_the_current_request_still_moves_the_order(): void {
		$newer = $this->newerRequest();

		$this->assertSame(200, $this->deliverFor('order.expired', $newer));

		$this->assertSame((int)\Configuration::get('PS_OS_CANCELED'), $this->currentStatus());
		$this->assertStringNotContainsString('earlier payment request', $this->lastNote());
	}

	/** The current request cancelled moves the order as expired does. */
	public function test_the_current_request_cancelled_moves_the_order(): void {
		$newer = $this->newerRequest();

		$this->assertSame(200, $this->deliverFor('order.cancelled', $newer));

		$this->assertSame((int)\Configuration::get('PS_OS_CANCELED'), $this->currentStatus());
		$this->assertStringNotContainsString('earlier payment request', $this->lastNote());
	}

	/** Paid by an earlier request: the current one expiring or cancelled later leaves it paid. */
	public function test_paid_by_an_earlier_request_never_goes_back(): void {
		$newer = $this->newerRequest();
		$this->deliverVector('order.paid');

		$this->assertSame(200, $this->deliverFor('order.partial', $newer));
		$this->assertSame(200, $this->deliverFor('order.expired', $newer));
		$this->assertSame(200, $this->deliverFor('order.cancelled', $newer));

		$this->assertSame((int)\Configuration::get('PS_OS_PAYMENT'), $this->currentStatus());
		$this->assertStringContainsString('The order was already paid, so its status is left as it is', $this->lastNote());
	}

	/** Both requests paid: the second is a note for the merchant to refund, and PrestaShop records one payment. */
	public function test_a_second_paid_request_is_a_note_to_refund(): void {
		$newer = $this->newerRequest();
		$this->deliverVector('order.paid');

		$this->assertSame(200, $this->deliverFor('order.overpaid', $newer));

		$this->assertSame((int)\Configuration::get('PS_OS_PAYMENT'), $this->currentStatus());
		$this->assertSame(1, $this->payments());
		$this->assertStringContainsString('another of its payment requests was paid too', $this->lastNote());
		$this->assertStringContainsString('Refund the extra payment', $this->lastNote());

		// And the other way round: the newer one paid first.
		$this->assertSame(200, $this->deliverVector('order.overpaid'));
		$this->assertStringContainsString('another of its payment requests was paid too', $this->lastNote());
		$this->assertSame(1, $this->payments());
	}

	/** More arriving on the request that paid it is a note, never a second payment. */
	public function test_an_overpayment_after_paid_is_a_note(): void {
		$this->deliverVector('order.paid');

		$this->assertSame(200, $this->deliverVector('order.overpaid'));

		$this->assertSame((int)\Configuration::get('PS_OS_PAYMENT'), $this->currentStatus());
		$this->assertSame(1, $this->payments());
		$this->assertStringContainsString('more than the amount due', $this->lastNote());
	}

	/** Applying fails (no status chosen): 500, and the event is given back so the retry applies it. */
	public function test_a_failed_change_is_retried(): void {
		$this->setting('MONOKULO_OS_PAID', '0');
		$this->assertSame(500, $this->deliverVector('order.paid'));
		$this->assertSame($this->awaiting(), $this->currentStatus());

		$this->setting('MONOKULO_OS_PAID', (string)\Configuration::get('PS_OS_PAYMENT'));
		$this->assertSame(200, $this->deliverVector('order.paid'));
		$this->assertSame((int)\Configuration::get('PS_OS_PAYMENT'), $this->currentStatus());
	}

	public function test_an_order_deleted_since_is_retried(): void {
		$this->db()->execute('DELETE FROM `' . _DB_PREFIX_ . 'orders` WHERE `id_order` = ' . (int)$this->order->id);
		\Cache::clean('*');

		$this->assertSame(500, $this->deliverVector('order.paid'));
		$this->assertStringContainsString('no longer exists', implode("\n", $this->log));
	}
}
