<?php
namespace Monokulo\PrestaShop\Tests\Support;

/**
 * A browser without JavaScript, for driving PrestaShop's back office and
 * Monokulo's pages over real HTTP: cookies, redirects followed by hand, and
 * forms filled by field name.
 */
final class Browser {
	private string $jar;

	public function __construct() {
		$this->jar = tempnam(sys_get_temp_dir(), 'jar');
	}

	public function __destruct() {
		@unlink($this->jar);
	}

	public function get(string $url, array $headers = []): Page {
		return $this->request('GET', $url, null, $headers);
	}

	/**
	 * Posts, and posts again where a 307 or 308 says to, as a browser does
	 * (PrestaShop sends its back office's old addresses to new ones that way).
	 *
	 * @param array<string, string>|string $fields form fields, or a raw body
	 */
	public function post(string $url, array|string $fields, array $headers = []): Page {
		$page = $this->request('POST', $url, $fields, $headers);
		for ($i = 0; $i < 5 && in_array($page->status, [307, 308], true) && $page->location() !== null; $i++) {
			$page = $this->request('POST', $page->location(), $fields, $headers);
		}

		return $page;
	}

	/** Uploads one file as the multipart field `field`. */
	public function upload(string $url, string $field, string $path, string $filename): Page {
		return $this->request('POST', $url, [$field => new \CURLFile($path, 'application/zip', $filename)], []);
	}

	/** Follows redirects from `page` (at most ten), as a browser would. */
	public function follow(Page $page): Page {
		for ($i = 0; $i < 10 && $page->location() !== null; $i++) {
			$page = $this->get($page->location());
		}

		return $page;
	}

	/**
	 * Submits the form on `page` whose action contains `action`, with every
	 * field it already has (hidden inputs, selected options, filled
	 * values) and `fields` on top.
	 *
	 * @param array<string, string> $fields
	 */
	public function submit(Page $page, string $action, array $fields = []): Page {
		$form = $page->form($action);

		return $this->post($form['action'], $fields + $form['fields']);
	}

	/**
	 * Submits the form on `page` that has a field named `field`, as submit()
	 * does.
	 *
	 * @param array<string, string> $fields
	 */
	public function submitWith(Page $page, string $field, array $fields = []): Page {
		$form = $page->formWith($field);

		return $this->post($form['action'], $fields + $form['fields']);
	}

	/** @param array<string, mixed>|string|null $body */
	private function request(string $method, string $url, array|string|null $body, array $headers): Page {
		$curl = curl_init($url);
		$lines = [];
		foreach ($headers as $name => $value) {
			$lines[] = $name . ': ' . $value;
		}
		$options = [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HEADER         => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_COOKIEJAR      => $this->jar,
			CURLOPT_COOKIEFILE     => $this->jar,
			CURLOPT_TIMEOUT        => 60,
			CURLOPT_HTTPHEADER     => $lines,
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_USERAGENT      => 'monokulo-prestashop-tests',
		];
		if ($body !== null) {
			$multipart = is_array($body) && array_filter($body, static fn($v) => $v instanceof \CURLFile);
			$options[CURLOPT_POSTFIELDS] = is_array($body) && !$multipart ? http_build_query($body) : $body;
		}
		curl_setopt_array($curl, $options);
		$raw = curl_exec($curl);
		if ($raw === false) {
			throw new \RuntimeException($method . ' ' . $url . ': ' . curl_error($curl));
		}
		$headerSize = (int)curl_getinfo($curl, CURLINFO_HEADER_SIZE);

		return new Page($url, (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE), substr($raw, 0, $headerSize), substr($raw, $headerSize));
	}
}
