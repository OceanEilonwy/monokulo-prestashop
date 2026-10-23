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
 * Monokulo answered, but not with what was asked for. `status` is its HTTP
 * status (the success's own when the answer had the wrong shape), `detail`
 * what went wrong, for the merchant's log. Monokulo's API says why in an
 * `application/problem+json` body: `problem` is its `type` (the problem's
 * page in Monokulo's docs, `…/problems/invalid-amount`), or '' when the
 * answer wasn't one.
 */
final class ApiError extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $detail,
        public readonly string $problem = '',
    ) {
        parent::__construct(sprintf('HTTP %d: %s', $status, $detail));
    }

    /** The problem's name, the last part of its `type` (`invalid-amount`), or ''. */
    public function problemName(): string
    {
        $path = (string) parse_url($this->problem, PHP_URL_PATH);

        return basename(rtrim($path, '/'));
    }

    /** The language key of what the customer is told. */
    public function customerMessageKey(): string
    {
        return match (true) {
            $this->problemName() === 'invalid-amount' => 'error_currency',
            $this->status === 429 => 'error_busy',
            $this->status === 401 || $this->status === 403 => 'error_unavailable',
            default => 'error_generic',
        };
    }

    /** A pointer for the merchant reading the log, or ''. */
    public function merchantHint(): string
    {
        return match (true) {
            $this->problemName() === 'invalid-amount' => ' (Monokulo cannot price this order: check the store\'s exchange rate settings in Monokulo, or turn Monero off for this currency)',
            $this->status === 401 => ' (the store secret key was rejected: connect the store again from the module settings)',
            $this->status === 403 => ' (Monokulo refused the store secret key for this: connect the store again from the module settings)',
            $this->status === 404 => ' (no Monokulo API at this address: check the Monokulo address in the module settings, and that your Monokulo is up to date)',
            $this->status === 429 => ' (rate limited: Monokulo is receiving too many orders from this store)',
            default => '',
        };
    }
}
