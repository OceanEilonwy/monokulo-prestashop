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
 * Where the client keeps each connection's API entry between requests:
 * Repository, in the connection's own row.
 */
interface EntryStore
{
    public function keepEntry(Connection $connection, Entry $entry): void;
}
