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
 * answer wasn't one. `retryAfter` is how many seconds Monokulo asked to
 * wait before trying again (the problem's `retry_after`, or `Retry-After`),
 * null when it didn't say.
 */
final class ApiError extends \RuntimeException
{
    /**
     * Monokulo can't make the order right now, and made none: no exchange
     * rate for its currency (`rates-unavailable`), or the payment engine is
     * busy (`engine-unavailable`). Sending the same request again later,
     * with the same idempotency key, is safe.
     */
    private const TRY_AGAIN = ['rates-unavailable', 'engine-unavailable'];

    public function __construct(
        public readonly int $status,
        public readonly string $detail,
        public readonly string $problem = '',
        public readonly ?int $retryAfter = null,
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
            $this->tryAgain() => 'error_try_again',
            $this->status === 429 => 'error_busy',
            $this->status === 401 || $this->status === 403 => 'error_unavailable',
            default => 'error_generic',
        };
    }

    /** Whether Monokulo made nothing for now and the same request may be sent again later. */
    public function tryAgain(): bool
    {
        return in_array($this->problemName(), self::TRY_AGAIN, true) || ($this->problem === '' && $this->status === 503);
    }

    /** A pointer for the merchant reading the log, or ''. */
    public function merchantHint(): string
    {
        $wait = $this->retryAfter !== null ? sprintf(', after %d seconds as Monokulo asked', $this->retryAfter) : '';

        return match (true) {
            $this->problemName() === 'rates-unavailable' => ' (Monokulo has no exchange rate for this currency right now: its rate providers are down or limiting it. No Monokulo order was made; the customer was asked to try again shortly' . $wait . ')',
            $this->tryAgain() => ' (Monokulo\'s payment engine is unavailable right now. No Monokulo order was made; the customer was asked to try again shortly' . $wait . ')',
            $this->problemName() === 'invalid-amount' => ' (Monokulo cannot price this order: check the store\'s exchange rate settings in Monokulo, or turn Monero off for this currency)',
            $this->status === 401 => ' (the store secret key was rejected: connect the store again from the module settings)',
            $this->status === 403 => ' (Monokulo refused the store secret key for this: connect the store again from the module settings)',
            $this->status === 404 => ' (no Monokulo API at this address: check the Monokulo address in the module settings, and that your Monokulo is up to date)',
            $this->status === 429 => ' (rate limited: Monokulo is receiving too many orders from this store)',
            default => '',
        };
    }
}
