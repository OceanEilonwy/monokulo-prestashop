<?php

/**
 * Monokulo for PrestaShop
 *
 * @author    Monokulo
 * @copyright Monokulo
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Monokulo\PrestaShop;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The module's own tables.
 *
 * - `monokulo_connection`: one row per shop host that has connected.
 * - `monokulo_order`: each Monokulo order the shop created, and which
 *   PrestaShop order it pays. A PrestaShop order can have more than one when
 *   its total changed after a first attempt; the newest is the live one.
 * - `monokulo_event`: webhook events already applied, so a delivery that is
 *   sent again (its answer was lost) changes nothing the second time.
 * - `monokulo_connect_nonce`: connect attempts the admin started, each
 *   good once and for ten minutes.
 *
 * Uninstalling the module keeps these tables: orders refer to them, and
 * installing again picks the connections back up.
 */
final class Repository
{
    public const NONCE_TTL_SECONDS = 600;

    public function __construct(
        private readonly Database $db,
    ) {
    }

    /** @return list<string> CREATE TABLE statements, safe to run again. */
    private function schema(): array
    {
        $p = $this->db->prefix();

        return [
            "CREATE TABLE IF NOT EXISTS `{$p}monokulo_connection` (
                `host` VARCHAR(255) NOT NULL,
                `endpoint` VARCHAR(255) NOT NULL,
                `public_key` VARCHAR(128) NOT NULL,
                `secret_token` VARCHAR(255) NOT NULL,
                `webhook_secret` VARCHAR(255) NOT NULL,
                `connected_at` INT(11) NOT NULL,
                PRIMARY KEY (`host`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
            "CREATE TABLE IF NOT EXISTS `{$p}monokulo_order` (
                `seq` INT(11) NOT NULL AUTO_INCREMENT,
                `monokulo_order_id` VARCHAR(64) NOT NULL,
                `id_order` INT(10) UNSIGNED NOT NULL,
                `id_shop` INT(10) UNSIGNED NOT NULL,
                `host` VARCHAR(255) NOT NULL,
                `amount` VARCHAR(40) NOT NULL,
                `currency` VARCHAR(8) NOT NULL,
                `created_at` INT(11) NOT NULL,
                PRIMARY KEY (`monokulo_order_id`),
                UNIQUE KEY `seq` (`seq`),
                KEY `id_order` (`id_order`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
            "CREATE TABLE IF NOT EXISTS `{$p}monokulo_event` (
                `event_id` VARCHAR(64) NOT NULL,
                `monokulo_order_id` VARCHAR(64) NOT NULL,
                `applied_at` INT(11) NOT NULL,
                PRIMARY KEY (`event_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
            "CREATE TABLE IF NOT EXISTS `{$p}monokulo_connect_nonce` (
                `nonce` CHAR(32) NOT NULL,
                `id_shop` INT(10) UNSIGNED NOT NULL,
                `host` VARCHAR(255) NOT NULL,
                `admin_return` TEXT NOT NULL,
                `expires_at` INT(11) NOT NULL,
                PRIMARY KEY (`nonce`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        ];
    }

    public function install(): void
    {
        foreach ($this->schema() as $statement) {
            $this->db->execute($statement);
        }
    }

    public function connection(string $host): ?Connection
    {
        $row = $this->db->row('SELECT * FROM `' . $this->table('connection') . '` WHERE `host` = ' . $this->db->quote($host));
        if (!$row) {
            return null;
        }

        return new Connection(
            $row['host'],
            $row['endpoint'],
            $row['public_key'],
            $row['secret_token'],
            $row['webhook_secret'],
            (int) $row['connected_at'],
        );
    }

    public function saveConnection(Connection $c): void
    {
        $q = fn (string $v): string => $this->db->quote($v);
        $this->db->execute(
            'REPLACE INTO `' . $this->table('connection') . '` (`host`, `endpoint`, `public_key`, `secret_token`, `webhook_secret`, `connected_at`) VALUES ('
            . implode(', ', [$q($c->host), $q($c->endpoint), $q($c->publicKey), $q($c->secretToken), $q($c->webhookSecret), (int) $c->connectedAt])
            . ')'
        );
    }

    /** Remembers a connect attempt the admin started; returns its nonce. */
    public function createNonce(int $shopId, string $host, string $adminReturn, int $now): string
    {
        $nonce = bin2hex(random_bytes(16));
        $this->db->execute('DELETE FROM `' . $this->table('connect_nonce') . '` WHERE `expires_at` < ' . $now);
        $this->db->execute(
            'INSERT INTO `' . $this->table('connect_nonce') . '` (`nonce`, `id_shop`, `host`, `admin_return`, `expires_at`) VALUES ('
            . $this->db->quote($nonce) . ', ' . $shopId . ', ' . $this->db->quote($host) . ', ' . $this->db->quote($adminReturn) . ', ' . ($now + self::NONCE_TTL_SECONDS) . ')'
        );

        return $nonce;
    }

    /**
     * Uses up a nonce: its attempt (`shop_id`, `host`, `admin_return`) the
     * first time it is presented within ten minutes, null ever after.
     *
     * @return array{shop_id: int, host: string, admin_return: string}|null
     */
    public function consumeNonce(string $nonce, int $now): ?array
    {
        if (!preg_match('/\A[0-9a-f]{32}\z/', $nonce)) {
            return null;
        }
        $row = $this->db->row('SELECT * FROM `' . $this->table('connect_nonce') . '` WHERE `nonce` = ' . $this->db->quote($nonce));
        $this->db->execute('DELETE FROM `' . $this->table('connect_nonce') . '` WHERE `nonce` = ' . $this->db->quote($nonce));
        // Only the request whose DELETE removed the row may use it.
        if (!$row || $this->db->affected() !== 1 || (int) $row['expires_at'] < $now) {
            return null;
        }

        return ['shop_id' => (int) $row['id_shop'], 'host' => $row['host'], 'admin_return' => $row['admin_return']];
    }

    /**
     * Remembers that `monokuloOrderId` pays PrestaShop order `orderId`. True
     * the first time, false when it was already known (a retried request got
     * the same Monokulo order back).
     */
    public function recordOrder(string $monokuloOrderId, int $orderId, int $shopId, string $host, string $amount, string $currency, int $now): bool
    {
        $this->db->execute(
            'INSERT IGNORE INTO `' . $this->table('order') . '` (`monokulo_order_id`, `id_order`, `id_shop`, `host`, `amount`, `currency`, `created_at`) VALUES ('
            . $this->db->quote($monokuloOrderId) . ', ' . $orderId . ', ' . $shopId . ', ' . $this->db->quote($host) . ', '
            . $this->db->quote($amount) . ', ' . $this->db->quote($currency) . ', ' . $now . ')'
        );

        return $this->db->affected() === 1;
    }

    /**
     * The PrestaShop order a Monokulo order pays, and whether it is that
     * order's newest Monokulo order.
     *
     * @return array{order_id: int, shop_id: int, host: string, latest: bool}|null
     */
    public function findOrder(string $monokuloOrderId): ?array
    {
        $row = $this->db->row('SELECT * FROM `' . $this->table('order') . '` WHERE `monokulo_order_id` = ' . $this->db->quote($monokuloOrderId));
        if (!$row) {
            return null;
        }
        $newest = $this->db->row(
            'SELECT `monokulo_order_id` FROM `' . $this->table('order') . '` WHERE `id_order` = ' . (int) $row['id_order']
            . ' ORDER BY `seq` DESC'
        );

        return [
            'order_id' => (int) $row['id_order'],
            'shop_id' => (int) $row['id_shop'],
            'host' => $row['host'],
            'latest' => $newest && $newest['monokulo_order_id'] === $row['monokulo_order_id'],
        ];
    }

    /**
     * Claims a webhook event: true the first time `eventId` is seen, false for
     * a repeat, so each event changes the order once.
     */
    public function claimEvent(string $eventId, string $monokuloOrderId, int $now): bool
    {
        $this->db->execute(
            'INSERT IGNORE INTO `' . $this->table('event') . '` (`event_id`, `monokulo_order_id`, `applied_at`) VALUES ('
            . $this->db->quote($eventId) . ', ' . $this->db->quote($monokuloOrderId) . ', ' . $now . ')'
        );

        return $this->db->affected() === 1;
    }

    /** Gives a claimed event back, after applying it failed, so a retry applies it. */
    public function releaseEvent(string $eventId): void
    {
        $this->db->execute('DELETE FROM `' . $this->table('event') . '` WHERE `event_id` = ' . $this->db->quote($eventId));
    }

    private function table(string $name): string
    {
        return $this->db->prefix() . 'monokulo_' . $name;
    }
}
