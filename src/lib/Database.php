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
 * What the repository needs of a database: Shop\PrestaShopDatabase gives it
 * PrestaShop's own connection.
 */
interface Database
{
    /** Runs a statement that returns no rows. */
    public function execute(string $sql): void;

    /**
     * The first row `sql` selects, or null.
     *
     * @return array<string, string>|null
     */
    public function row(string $sql): ?array;

    /** How many rows the last statement changed. */
    public function affected(): int;

    /** `value` quoted as an SQL string literal. */
    public function quote(string $value): string;

    /** The table name prefix (`ps_`). */
    public function prefix(): string;
}
