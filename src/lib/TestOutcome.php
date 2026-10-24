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
 * What the settings page tells the admin after "Test connection": how the
 * entry's `send-test-webhook` form answered, as a back office notice.
 *
 * - `notice`: `confirmation` (delivered), `warning` (try again later), or
 *   `error`;
 * - `key`: the message's language key (Monokulo::text), `params` what goes
 *   in it, and `nextAttemptAt` when Monokulo tries an undelivered test
 *   again (Unix seconds, for `%next%`; null when it won't);
 * - `log`: a line for the module's log when it went wrong, or ''.
 */
final class TestOutcome
{
    /**
     * @param array<string, string> $params
     */
    private function __construct(
        public readonly string $notice,
        public readonly string $key,
        public readonly array $params = [],
        public readonly ?int $nextAttemptAt = null,
        public readonly string $log = '',
    ) {
    }

    /**
     * The form's `200` answer: the test was sent, and `delivered` says
     * whether the shop's webhook answered `2xx`.
     *
     * @param array<string, mixed> $answer
     */
    public static function answered(array $answer): self
    {
        $event = is_string($answer['event_id'] ?? null) ? $answer['event_id'] : '';
        $status = is_int($answer['status'] ?? null) ? $answer['status'] : null;
        $duration = is_int($answer['duration_ms'] ?? null) ? $answer['duration_ms'] : 0;
        if (($answer['delivered'] ?? false) === true) {
            return new self('confirmation', 'test_delivered', ['%event%' => $event, '%status%' => (string) $status, '%duration%' => (string) $duration]);
        }

        $error = is_string($answer['error'] ?? null) && $answer['error'] !== '' ? $answer['error'] : ($status !== null ? 'answered ' . $status : 'no answer');
        $next = is_int($answer['next_attempt_at'] ?? null) ? $answer['next_attempt_at'] : null;
        $response = is_string($answer['response'] ?? null) ? $answer['response'] : '';

        return new self(
            'error',
            $next !== null ? 'test_failed_retry' : 'test_failed',
            ['%event%' => $event, '%error%' => $error, '%duration%' => (string) $duration],
            $next,
            sprintf('Test webhook %s was not delivered to the shop: %s (after %d ms)%s', $event, $error, $duration, $response !== '' ? '; it answered: ' . $response : '')
        );
    }

    /** Monokulo refused the form, or the entry doesn't offer it (status 0). */
    public static function refused(ApiError $e): self
    {
        $log = sprintf('Sending a test webhook failed: HTTP %d: %s', $e->status, $e->detail);

        return match (true) {
            $e->status === 0 => new self('error', 'test_not_offered', [], null, $log . ' (update your Monokulo: it has no send-test-webhook form)'),
            $e->problemName() === 'rate-limited' || $e->status === 429 => new self('warning', 'test_rate_limited', ['%seconds%' => (string) max(1, $e->retryAfter ?? 60)]),
            $e->problemName() === 'not-found' => new self('error', 'test_not_found', [], null, $log . ' (Monokulo has no webhook for this shop: connect the shop again)'),
            $e->status === 401 || $e->status === 403 => new self('error', 'test_key_refused', [], null, $log . ' (the store secret key was refused: connect the shop again)'),
            default => new self('error', 'test_refused', ['%status%' => (string) $e->status], null, $log),
        };
    }

    /** Monokulo couldn't be reached from the shop's server. */
    public static function unreachable(TransportError $e, string $api): self
    {
        return new self('error', 'test_unreachable', [], null, sprintf('Sending a test webhook failed: could not reach %s: %s', $api, $e->getMessage()));
    }
}
