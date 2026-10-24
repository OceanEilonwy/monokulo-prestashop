<?php
namespace Monokulo\PrestaShop\Tests\Unit;

use Monokulo\PrestaShop\ApiError;
use Monokulo\PrestaShop\TestOutcome;
use Monokulo\PrestaShop\Tests\Support\Api;
use Monokulo\PrestaShop\TransportError;
use PHPUnit\Framework\TestCase;

/**
 * What "Test connection" tells the admin, from the send-test-webhook
 * form's answers as Monokulo's OpenAPI document records them.
 */
final class TestOutcomeTest extends TestCase {
	private static function problem(string $name): ApiError {
		$problem = Api::recordedProblem('post', '/api/v1/webhook-tests', $name);

		return new ApiError($problem['status'], $problem['detail'], $problem['type'], $problem['retry_after'] ?? null);
	}

	public function test_delivered_says_so_with_the_status_and_how_long(): void {
		$answer = Api::testDelivered();
		$outcome = TestOutcome::answered($answer);

		$this->assertSame('confirmation', $outcome->notice);
		$this->assertSame('test_delivered', $outcome->key);
		$this->assertSame(['%event%' => $answer['event_id'], '%status%' => '200', '%duration%' => '12'], $outcome->params);
		$this->assertNull($outcome->nextAttemptAt);
		$this->assertSame('', $outcome->log, 'nothing went wrong');
	}

	public function test_not_delivered_says_why_and_when_monokulo_tries_again(): void {
		$answer = Api::testNotDelivered();
		$outcome = TestOutcome::answered($answer);

		$this->assertSame('error', $outcome->notice);
		$this->assertSame('test_failed_retry', $outcome->key);
		$this->assertSame('answered 500', $outcome->params['%error%']);
		$this->assertSame($answer['event_id'], $outcome->params['%event%']);
		$this->assertSame(1760020452, $outcome->nextAttemptAt);
		$this->assertStringContainsString('was not delivered to the shop: answered 500', $outcome->log);
		$this->assertStringContainsString('HTTP/1.1 500 Internal Server Error', $outcome->log, 'with what the shop answered');
	}

	/** Nothing answered (no status), and Monokulo won't try again. */
	public function test_not_delivered_without_an_answer_or_a_retry(): void {
		$answer = Api::testNotDelivered();
		unset($answer['status'], $answer['response'], $answer['next_attempt_at']);
		$answer['error'] = 'timed out';
		$outcome = TestOutcome::answered($answer);

		$this->assertSame('test_failed', $outcome->key);
		$this->assertSame('timed out', $outcome->params['%error%']);
		$this->assertNull($outcome->nextAttemptAt);

		unset($answer['error']);
		$this->assertSame('no answer', TestOutcome::answered($answer)->params['%error%']);
	}

	public function test_rate_limited_says_how_long_to_wait(): void {
		$outcome = TestOutcome::refused(self::problem('rate-limited'));

		$this->assertSame('warning', $outcome->notice);
		$this->assertSame('test_rate_limited', $outcome->key);
		$this->assertSame(['%seconds%' => '120'], $outcome->params);
		$this->assertSame('', $outcome->log, 'nothing to log: the admin waits');
		$this->assertSame(['%seconds%' => '60'], TestOutcome::refused(new ApiError(429, 'slow down'))->params, 'a minute when Monokulo did not say');
	}

	public function test_no_webhook_to_test_asks_to_connect_again(): void {
		$outcome = TestOutcome::refused(self::problem('not-found'));

		$this->assertSame('error', $outcome->notice);
		$this->assertSame('test_not_found', $outcome->key);
		$this->assertStringContainsString("There's no such webhook for this store", $outcome->log);
		$this->assertStringContainsString('connect the shop again', $outcome->log);
	}

	public function test_other_refusals_say_so_and_are_logged(): void {
		$this->assertSame('test_key_refused', TestOutcome::refused(self::problem('unauthorized'))->key);
		$this->assertSame('test_key_refused', TestOutcome::refused(self::problem('forbidden'))->key);
		$internal = TestOutcome::refused(self::problem('internal'));
		$this->assertSame(['test_refused', ['%status%' => '500']], [$internal->key, $internal->params]);
		$this->assertStringContainsString('Something went wrong on this Monokulo', $internal->log);

		$this->assertSame('test_not_offered', TestOutcome::refused(new ApiError(0, 'the API entry offers this key no send-test-webhook form'))->key);
		$unreachable = TestOutcome::unreachable(new TransportError('Connection refused'), 'https://pay.example.com/api/v1');
		$this->assertSame('test_unreachable', $unreachable->key);
		$this->assertStringContainsString('could not reach https://pay.example.com/api/v1: Connection refused', $unreachable->log);
	}
}
