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
     * rate for its currency (`rates-unavailable`), the payment engine is
     * busy (`engine-unavailable`), or the store's settings can't be read
     * (`store-unavailable`). Sending the same request again later, with the
     * same idempotency key, is safe.
     */
    private const TRY_AGAIN = ['rates-unavailable', 'engine-unavailable', 'store-unavailable'];

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

    /**
     * A pointer for the merchant reading the log about making an order
     * (`POST /api/v1/orders`, and reading the API entry for it), or ''.
     */
    public function merchantHint(): string
    {
        $wait = $this->retryAfter !== null ? sprintf(', after %d seconds as Monokulo asked', $this->retryAfter) : '';
        $kept = 'No Monokulo order was made; the customer was asked to try again shortly' . $wait;

        return match (true) {
            $this->problemName() === 'rates-unavailable' => ' (Monokulo has no exchange rate for this currency right now: its rate providers are down or limiting it. ' . $kept . ')',
            $this->problemName() === 'store-unavailable' => ' (Monokulo could not read this store\'s settings, its embed policy among them: its database did not answer. ' . $kept . '. If it keeps happening, your Monokulo\'s operator should check its logs and database)',
            $this->tryAgain() => ' (Monokulo\'s payment engine is unavailable right now. ' . $kept . ')',
            $this->problemName() === 'invalid-amount' => ' (Monokulo cannot price this order: check the store\'s exchange rate settings in Monokulo, or turn Monero off for this currency)',
            $this->problemName() === 'idempotency-conflict' => ' (Monokulo already has an order made with this order\'s idempotency key, for a different reference: another PrestaShop connected to the same Monokulo store, such as a copy or staging shop, may use the same order numbers. Connect each shop to its own Monokulo store)',
            $this->problemName() === 'invalid-request' => ' (Monokulo refused the module\'s order request: update the module, and check that your Monokulo is up to date)',
            $this->problemName() === 'too-large' => ' (the order request is larger than Monokulo takes: update the module, and check that your Monokulo is up to date)',
            $this->problemName() === 'unsupported-media-type' => ' (Monokulo did not receive the order request as JSON: a proxy between the shop and Monokulo may be changing requests. Check the Monokulo address in the module settings)',
            $this->problemName() === 'challenge-required' => ' (Monokulo asked for a browser\'s challenge, which it never asks of a request with the store secret key: a proxy between the shop and Monokulo may be dropping the key. Check the Monokulo address in the module settings)',
            $this->problemName() === 'internal' || ($this->problem === '' && $this->status >= 500) => ' (something went wrong on your Monokulo: its operator can find what in Monokulo\'s logs)',
            $this->status === 401 => ' (the store secret key was rejected: connect the store again from the module settings)',
            $this->status === 403 => ' (Monokulo refused the store secret key for this: connect the store again from the module settings)',
            $this->status === 404 => ' (no Monokulo API at this address: check the Monokulo address in the module settings, and that your Monokulo is up to date)',
            $this->status === 429 => ' (rate limited: Monokulo is receiving too many orders from this store' . $wait . ')',
            default => '',
        };
    }

    /** A pointer for the merchant reading the log about the connect handshake's finish, or ''. */
    public function connectHint(): string
    {
        $wait = $this->retryAfter !== null ? sprintf(' %d seconds', $this->retryAfter) : ' a minute';

        return match ($this->problemName()) {
            'no-public-address' => ' (your Monokulo has no public address yet: its operator sets it in Monokulo\'s settings, then you connect again)',
            'invalid-connect-code' => ' (the connect code was unknown, already used or expired: it lasts 10 minutes. Connect again from the module settings)',
            'not-found' => ' (Monokulo does not connect PrestaShop shops at this address: check the Monokulo address in the module settings, and that your Monokulo is up to date)',
            'invalid-request', 'too-large' => ' (Monokulo refused the module\'s request: update the module, and check that your Monokulo is up to date)',
            'unsupported-media-type' => ' (Monokulo did not receive the request as JSON: a proxy between the shop and Monokulo may be changing requests)',
            'rate-limited' => ' (too many connect attempts from this shop: wait' . $wait . ', then connect again)',
            'internal' => ' (something went wrong on your Monokulo: its operator can find what in Monokulo\'s logs)',
            default => '',
        };
    }

    /** A pointer for the merchant reading the log about sending the module's errors to Monokulo, or ''. */
    public function logsHint(): string
    {
        return match ($this->problemName()) {
            'logs-off' => ' (client logs are off for this store: turn them on in the store\'s settings in Monokulo, or turn off sending errors to Monokulo in the module settings)',
            'unauthorized', 'forbidden' => ' (Monokulo takes the module\'s errors only with the store secret key: connect the store again from the module settings)',
            'rate-limited' => ' (the module sent Monokulo too many reports: these errors are in this log only)',
            'invalid-request', 'too-large', 'unsupported-media-type' => ' (Monokulo refused the module\'s report: update the module, and check that your Monokulo is up to date)',
            default => '',
        };
    }
}
