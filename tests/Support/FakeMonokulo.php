<?php
namespace Monokulo\PrestaShop\Tests\Support;

/**
 * Runs tests/Support/fake-monokulo.php on a free local port for the length
 * of a test class, and lets a test say what it answers and read back what
 * it was sent.
 */
final class FakeMonokulo {
	/** @var resource */
	private $process;
	private string $dir;
	public readonly string $url;

	public function __construct() {
		$this->dir = sys_get_temp_dir() . '/fake-monokulo-' . bin2hex(random_bytes(6));
		mkdir($this->dir);
		$port = self::freePort();
		$this->url = 'http://127.0.0.1:' . $port;
		$this->process = proc_open(
			[PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/fake-monokulo.php'],
			[0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->dir . '/server.log', 'a'], 2 => ['file', $this->dir . '/server.log', 'a']],
			$pipes,
			null,
			['FAKE_MONOKULO_DIR' => $this->dir]
		);
		for ($i = 0; $i < 100; $i++) {
			$socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
			if ($socket) {
				fclose($socket);

				return;
			}
			usleep(50000);
		}

		throw new \RuntimeException('the fake Monokulo did not start: ' . (string)@file_get_contents($this->dir . '/server.log'));
	}

	/** Answers `METHOD path` with `status` and `body` (a string, or JSON-encoded). */
	public function respond(string $method, string $path, int $status, mixed $body, int $delaySeconds = 0): void {
		$responses = is_file($this->dir . '/responses.json') ? json_decode((string)file_get_contents($this->dir . '/responses.json'), true) : [];
		$responses[$method . ' ' . $path] = ['status' => $status, 'body' => $body, 'delay' => $delaySeconds];
		file_put_contents($this->dir . '/responses.json', json_encode($responses));
	}

	/** @return list<array{method: string, path: string, query: string, headers: array<string, string>, body: string}> */
	public function requests(): array {
		if (!is_file($this->dir . '/requests.jsonl')) {
			return [];
		}
		$out = [];
		foreach (file($this->dir . '/requests.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
			$out[] = json_decode($line, true);
		}

		return $out;
	}

	/** Forgets every answer and request so far. */
	public function reset(): void {
		@unlink($this->dir . '/responses.json');
		@unlink($this->dir . '/requests.jsonl');
	}

	public function stop(): void {
		proc_terminate($this->process);
		proc_close($this->process);
		foreach (glob($this->dir . '/*') ?: [] as $file) {
			unlink($file);
		}
		rmdir($this->dir);
	}

	/** A port nothing listens on, closed again: the server takes it next. */
	public static function freePort(): int {
		$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
		$name = stream_socket_get_name($socket, false);
		fclose($socket);

		return (int)substr((string)strrchr((string)$name, ':'), 1);
	}
}
