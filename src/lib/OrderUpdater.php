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
 * Changes a PrestaShop order for one webhook event: Shop\Orders
 * implements it with PrestaShop's order history and order messages.
 */
interface OrderUpdater
{
    /**
     * @param bool $latest whether `event` is about the order's newest Monokulo order
     */
    public function apply(int $orderId, Decision $decision, WebhookEvent $event, bool $latest, Connection $connection): void;
}
