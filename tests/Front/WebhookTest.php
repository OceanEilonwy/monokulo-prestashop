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
		$this->assertStringContainsString(self::$monokulo->url . '/dashboard/stores/917701c2-15c7-4bcb-bbc8-72d9ab28e785/orders/' . Vectors::orderId(), $this->lastNote());
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
	 * The order's total changed after a first payment request, so a newer one
	 * exists. The older one being paid doesn't mark the order paid.
	 */
	public function test_paying_an_older_request_is_flagged_not_marked_paid(): void {
		$this->repository()->recordOrder('ord_newer', (int)$this->order->id, 1, self::HOST, '20.00', 'GBP', 1760000001);

		$this->assertSame(200, $this->deliverVector('order.paid'));
		$this->assertSame($this->awaiting(), $this->currentStatus());
		$this->assertStringContainsString('older payment request for this order was paid', $this->lastNote());
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
