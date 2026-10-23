<?php
namespace Monokulo\PrestaShop\Tests\Support;

/**
 * Carts and orders made with PrestaShop's own classes, in a booted
 * PrestaShop: for the in-process suites, and (through make-order.php) for
 * the HTTP suite's webhooks.
 */
final class Fixtures {
	/** A mug from PrestaShop's demo catalogue. */
	public const MUG = 6;

	/**
	 * A guest's cart, ready to pay: a customer, a UK address, and `quantity`
	 * of each product, in the shop's currency. It becomes the context's cart.
	 *
	 * @param list<int> $products
	 */
	public static function cart(array $products = [self::MUG], int $quantity = 1): \Cart {
		$customer = new \Customer();
		$customer->firstname = 'Ada';
		$customer->lastname = 'Lovelace';
		$customer->email = 'ada-' . bin2hex(random_bytes(4)) . '@example.test';
		$customer->passwd = md5(random_bytes(16)); // a guest's: never used to sign in
		$customer->is_guest = 1;
		$customer->id_shop = (int)\Context::getContext()->shop->id;
		$customer->id_default_group = (int)\Configuration::get('PS_GUEST_GROUP');
		$customer->add();

		$address = new \Address();
		$address->id_customer = (int)$customer->id;
		$address->alias = 'Home';
		$address->firstname = 'Ada';
		$address->lastname = 'Lovelace';
		$address->address1 = '12 Analytical Row';
		$address->city = 'London';
		$address->postcode = 'N1 1AA';
		$address->id_country = (int)\Country::getByIso('GB');
		$address->phone = '0123456789';
		$address->add();

		$cart = new \Cart();
		$cart->id_customer = (int)$customer->id;
		$cart->id_guest = 0;
		$cart->id_address_delivery = (int)$address->id;
		$cart->id_address_invoice = (int)$address->id;
		$cart->id_currency = (int)\Configuration::get('PS_CURRENCY_DEFAULT');
		$cart->id_lang = (int)\Configuration::get('PS_LANG_DEFAULT');
		$cart->id_shop = (int)\Context::getContext()->shop->id;
		$cart->id_shop_group = (int)\Context::getContext()->shop->id_shop_group;
		$cart->secure_key = $customer->secure_key;
		$cart->add();
		foreach ($products as $product) {
			$cart->updateQty($quantity, $product);
		}

		$context = \Context::getContext();
		$context->customer = $customer;
		$context->cart = $cart;

		return $cart;
	}

	/**
	 * An order as "Pay with Monero" places it, before Monokulo is asked for
	 * anything: in the merchant's "order placed" status.
	 *
	 * @param list<int> $products
	 */
	public static function placedOrder(array $products = [self::MUG]): \Order {
		$cart = self::cart($products);
		$module = new \Monokulo();
		$module->validateOrder((int)$cart->id, $module->orderStatus('pending'), (float)$cart->getOrderTotal(true, \Cart::BOTH), 'Monero (via Monokulo)', null, [], (int)$cart->id_currency, false, (new \Customer((int)$cart->id_customer))->secure_key);

		return new \Order((int)$module->currentOrder);
	}
}
