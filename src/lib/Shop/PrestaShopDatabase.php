<?php

/**
 * Monokulo for PrestaShop
 *
 * @author    Monokulo
 * @copyright Monokulo
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Monokulo\PrestaShop\Shop;

use Monokulo\PrestaShop\Database;

if (!defined('_PS_VERSION_')) {
    exit;
}

/** The repository's database: PrestaShop's own connection and table prefix. */
final class PrestaShopDatabase implements Database
{
    public function execute(string $sql): void
    {
        if (!\Db::getInstance()->execute($sql)) {
            throw new \RuntimeException('database: ' . \Db::getInstance()->getMsgError());
        }
    }

    public function row(string $sql): ?array
    {
        $row = \Db::getInstance()->getRow($sql, false);

        return is_array($row) ? $row : null;
    }

    public function affected(): int
    {
        return (int) \Db::getInstance()->Affected_Rows();
    }

    public function quote(string $value): string
    {
        return "'" . pSQL($value, true) . "'";
    }

    public function prefix(): string
    {
        return _DB_PREFIX_;
    }
}
