<?php
namespace Monokulo\PrestaShop\Tests\Support;

/**
 * Bodies of Monokulo's HTTP API (`/api/v1`) as a shop's secret key gets
 * them, for the stand-in Monokulo. The smoke suite checks their shape
 * against a real Monokulo's own description of its API.
 */
final class Api {
	private const CURIES = [['name' => 'mk', 'href' => 'https://docs.monokulo.com/rels/{rel}', 'templated' => true]];

	/** `GET /api/v1`: the entry, with its `create-order` form. */
	public static function entry(): array {
		return [
			'_links' => [
				'curies' => self::CURIES,
				'self' => ['href' => '/api/v1'],
				'mk:store' => ['href' => '/api/v1/store', 'title' => 'The store the key belongs to.'],
				'mk:orders' => ['href' => '/api/v1/orders', 'title' => 'The store\'s orders, newest first, a page at a time.'],
				'mk:events' => ['href' => '/api/v1/events', 'title' => 'The store\'s events.'],
				'service-desc' => ['href' => '/api/v1/openapi.json', 'type' => 'application/openapi+json'],
				'service-doc' => ['href' => 'https://docs.monokulo.com/api/', 'type' => 'text/html'],
			],
			'_templates' => [
				'create-order' => [
					'title' => 'Make an order.',
					'method' => 'POST',
					'target' => '/api/v1/orders',
					'contentType' => 'application/json',
					'properties' => [
						['name' => 'amount', 'required' => true, 'type' => 'text'],
						['name' => 'currency', 'required' => true, 'type' => 'text'],
						['name' => 'merchant_order_id', 'type' => 'text'],
						['name' => 'description', 'type' => 'text'],
						['name' => 'show_merchant_order_id', 'type' => 'checkbox'],
						['name' => 'idempotency_key', 'type' => 'text'],
					],
				],
			],
		];
	}

	/** An order just made: `201 Created` from the `create-order` form. */
	public static function order(string $orderId): array {
		return [
			'order_id' => $orderId,
			'status' => 'pending',
			'is_final' => false,
			'address' => '8BB...',
			'xmr_amount_piconero' => 120000000000,
			'amount_received_piconero' => 0,
			'amount' => '12.5',
			'currency' => 'EUR',
			'merchant_order_id' => 'XKBKNABJK',
			'description' => 'Order XKBKNABJK: Blue mug',
			'show_merchant_order_id' => false,
			'confirmations' => 0,
			'confirmations_required' => 10,
			'created_at' => 1760000000,
			'expires_at' => 1760003600,
			'_links' => [
				'curies' => self::CURIES,
				'self' => ['href' => '/api/v1/orders/' . $orderId],
				'collection' => ['href' => '/api/v1/orders'],
				'mk:order-events' => ['href' => '/api/v1/orders/' . $orderId . '/events'],
				'mk:checkout' => ['href' => '/checkout/' . $orderId, 'type' => 'text/html'],
			],
		];
	}

	/** An `application/problem+json` refusal. */
	public static function problem(string $name, int $status, string $title, string $detail): array {
		return ['type' => 'https://docs.monokulo.com/problems/' . $name, 'title' => $title, 'status' => $status, 'detail' => $detail];
	}
}
