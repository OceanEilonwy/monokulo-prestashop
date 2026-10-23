<?php

/**
 * Monokulo for PrestaShop
 *
 * @author    Monokulo
 * @copyright Monokulo
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 */

namespace Monokulo\PrestaShop;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * What one webhook event does to the shop's order.
 *
 * `status` names which of the merchant's configured order statuses to move
 * to (one of the STATUS_* constants), or is null to leave the status alone
 * and only add `note` to the order's history. `note` is a language key.
 */
final class Decision
{
    public const STATUS_WAITING = 'waiting';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_PAID = 'paid';
    public const STATUS_EXPIRED = 'expired';

    public function __construct(
        public readonly ?string $status,
        public readonly string $note,
        public readonly bool $notify = false,
        public readonly bool $paid = false,
    ) {
    }
}
