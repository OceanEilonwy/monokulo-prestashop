<?php
namespace Monokulo\PrestaShop\Tests\Unit;

use Monokulo\PrestaShop\Logger;
use Monokulo\PrestaShop\Trace;
use PHPUnit\Framework\TestCase;

final class TraceAndLoggerTest extends TestCase {
	public function test_each_call_is_a_new_span_in_the_same_trace(): void {
		$trace = new Trace();
		$first = $trace->traceparent();
		$second = $trace->traceparent();

		$this->assertMatchesRegularExpression('/\A00-' . $trace->id() . '-[0-9a-f]{16}-01\z/', $first);
		$this->assertNotSame($first, $second);
		$this->assertSame(substr($first, 0, 36), substr($second, 0, 36));
	}

	public function test_a_webhooks_trace_is_joined(): void {
		$trace = new Trace();

		$this->assertTrue($trace->adopt('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'));
		$this->assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $trace->id());
	}

	public function test_a_bad_traceparent_leaves_the_trace_alone(): void {
		$trace = new Trace('11111111111111111111111111111111');

		foreach (['', 'garbage', '01-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01', '00-00000000000000000000000000000000-00f067aa0ba902b7-01', '00-4bf92f3577b34da6a3ce929d0e0e4736-0000000000000000-01', '00-4BF92F3577B34DA6A3CE929D0E0E4736-00f067aa0ba902b7-01'] as $bad) {
			$this->assertFalse($trace->adopt($bad), $bad);
		}
		$this->assertSame('11111111111111111111111111111111', $trace->id());
	}

	public function test_log_lines_name_the_trace_and_only_problems_are_forwarded(): void {
		$lines = [];
		$levels = [];
		$log = new Logger(static function (string $line, string $level) use (&$lines, &$levels): void {
			$lines[] = $line;
			$levels[] = $level;
		}, new Trace('22222222222222222222222222222222'));

		$log->info('fine');
		$log->warning('odd');
		$log->error('bad');

		$this->assertSame([
			'INFO fine [trace 22222222222222222222222222222222]',
			'WARNING odd [trace 22222222222222222222222222222222]',
			'ERROR bad [trace 22222222222222222222222222222222]',
		], $lines);
		$this->assertSame(['info', 'warning', 'error'], $levels, 'the shop also files warnings and errors by level');
		$this->assertSame([
			['level' => 'warning', 'message' => 'odd', 'trace_id' => '22222222222222222222222222222222'],
			['level' => 'error', 'message' => 'bad', 'trace_id' => '22222222222222222222222222222222'],
		], $log->forwardable());
	}

	/** Monokulo takes at most 20 entries a request. */
	public function test_at_most_twenty_entries_are_kept_for_forwarding(): void {
		$log = new Logger(static function (string $line): void {
		}, new Trace());

		for ($i = 0; $i < 25; $i++) {
			$log->error('e' . $i);
		}

		$this->assertCount(20, $log->forwardable());
		$this->assertSame('e19', $log->forwardable()[19]['message']);
	}
}
