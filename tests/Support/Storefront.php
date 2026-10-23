<?php
namespace Monokulo\PrestaShop\Tests\Support;

/**
 * A guest's checkout through the storefront, as a browser without
 * JavaScript sends it, form by form: a product into the cart, personal
 * information, an address, a carrier, then the payment step.
 */
final class Storefront {
	/** A mug from PrestaShop's demo catalogue. */
	public const MUG = 6;

	/** France: in the zone the demo shop's carriers deliver to. */
	private const ADDRESS = ['firstname' => 'Ada', 'lastname' => 'Lovelace', 'address1' => '12 rue de la Paix', 'city' => 'Paris', 'postcode' => '75002', 'phone' => '0123456789'];

	/** Puts `product` in the cart and walks the checkout to its payment step; returns that page. */
	public static function toPayment(Browser $browser, int $product = self::MUG): Page {
		$page = $browser->follow($browser->get(Shop::URL . 'index.php?controller=product&id_product=' . $product));
		$add = $page->formWith('id_product', 'add-to-cart-or-refresh');
		self::ok($browser->post($add['action'], ['add' => '1', 'action' => 'update', 'qty' => '1'] + $add['fields']));

		$page = self::checkout($browser);
		$personal = $page->formWith('submitCreate');
		self::step($browser, $personal['action'], [
			'id_gender'        => '1',
			'firstname'        => 'Ada',
			'lastname'         => 'Lovelace',
			'email'            => 'ada-' . bin2hex(random_bytes(4)) . '@example.test',
			'password'         => '',
			'customer_privacy' => '1',
			'psgdpr'           => '1',
			'submitCreate'     => '1',
			'continue'         => '1',
		] + $personal['fields']);

		$page = self::checkout($browser);
		$address = $page->formWith('saveAddress');
		$country = (int)Shop::rows("SELECT id_country FROM ps_country WHERE iso_code = 'FR'")[0]['id_country'];
		self::step($browser, $address['action'], ['id_country' => (string)$country, 'saveAddress' => 'delivery', 'use_same_address' => '1', 'confirm-addresses' => '1'] + self::ADDRESS + $address['fields']);

		$page = self::checkout($browser);
		$delivery = $page->formWith(id: 'js-delivery');
		self::step($browser, $delivery['action'], ['confirmDeliveryOption' => '1'] + $delivery['fields']);

		$page = self::checkout($browser);
		// The payment step is the current one (PrestaShop 9's theme marks it
		// step--current, 8's classic theme -current).
		if (!preg_match('/<section[^>]*"checkout-payment-step"[^>]*>/', $page->body, $tag) || !preg_match('/(step--current|\s-current)\b/', $tag[0])) {
			throw new \RuntimeException('the checkout did not reach its payment step: ' . substr($page->text(), 0, 1500));
		}

		return $page;
	}

	/** The payment step's form for Monokulo, or null when Monero isn't offered. */
	public static function moneroForm(Page $payment): ?array {
		try {
			$form = $payment->form('/module/monokulo/validation');
		} catch (\RuntimeException) {
			return null;
		}

		return $form;
	}

	/** Presses "Place order" with Monero chosen; returns the answer, unfollowed. */
	public static function payWithMonero(Browser $browser, Page $payment): Page {
		$form = self::moneroForm($payment);
		if ($form === null) {
			throw new \RuntimeException('Monero is not offered at the payment step: ' . substr($payment->text(), 0, 1500));
		}

		return $browser->post($form['action'], $form['fields'] + ['conditions_to_approve[terms-and-conditions]' => '1']);
	}

	private static function checkout(Browser $browser): Page {
		return $browser->follow($browser->get(Shop::URL . 'index.php?controller=order'));
	}

	/** One checkout step: PrestaShop answers a good one by sending the browser back to the checkout. */
	private static function step(Browser $browser, string $action, array $fields): void {
		$answer = $browser->post($action, $fields);
		if ($answer->status !== 302) {
			throw new \RuntimeException('checkout step ' . $action . ' answered ' . $answer->status . ': ' . substr($answer->text(), 0, 1500));
		}
	}

	private static function ok(Page $answer): void {
		if ($answer->status >= 400) {
			throw new \RuntimeException('adding to the cart answered ' . $answer->status . ': ' . substr($answer->text(), 0, 800));
		}
	}
}
