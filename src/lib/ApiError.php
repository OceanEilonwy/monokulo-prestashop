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
 * Monokulo answered, but not with what was asked for. `status` is its HTTP
 * status (200 when the answer was a success with the wrong shape), `detail`
 * its error message or raw body, for the merchant's log.
 */
final class ApiError extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $detail,
    ) {
        parent::__construct(sprintf('HTTP %d: %s', $status, $detail));
    }

    /** The language key of what the customer is told. */
    public function customerMessageKey(): string
    {
        return match (true) {
            $this->status === 429 => 'error_busy',
            $this->status === 401 || $this->status === 403 => 'error_unavailable',
            default => 'error_generic',
        };
    }

    /** A pointer for the merchant reading the log, or ''. */
    public function merchantHint(): string
    {
        return match ($this->status) {
            401 => ' (the store secret key was rejected: connect the store again from the extension settings)',
            403 => ' (Monokulo refused the order: check the store\'s domain settings in Monokulo)',
            404 => ' (Monokulo does not know this store\'s public key: connect the store again)',
            429 => ' (rate limited: Monokulo is receiving too many orders from this store)',
            default => '',
        };
    }
}
