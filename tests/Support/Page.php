<?php
namespace Monokulo\PrestaShop\Tests\Support;

/** One HTTP response, with what a test reads from it. */
final class Page {
	public function __construct(
		public readonly string $url,
		public readonly int $status,
		public readonly string $headers,
		public readonly string $body,
	) {
	}

	/** Where a redirect goes, made absolute, or null. */
	public function location(): ?string {
		if (!preg_match('/^location:\s*(.+?)\r?$/mi', $this->headers, $m)) {
			return null;
		}

		return self::absolute(trim($m[1]), $this->url);
	}

	/** @return array<string, mixed> */
	public function json(): array {
		$data = json_decode($this->body, true);
		if (!is_array($data)) {
			throw new \RuntimeException('not JSON from ' . $this->url . ' (' . $this->status . '): ' . substr($this->body, 0, 500));
		}

		return $data;
	}

	/**
	 * The form whose action contains `action`: its absolute action and its
	 * fields by name (inputs with a value, checked boxes, each select's
	 * selected or first option, textareas).
	 *
	 * @return array{action: string, fields: array<string, string>}
	 */
	public function form(string $action): array {
		return $this->findForm(static fn(string $target, array $fields, \DOMElement $form): bool => str_contains($target, $action), 'with action containing "' . $action . '"');
	}

	/**
	 * The form that has a field named `field` (or, with `id`, the form with
	 * that id), as form() gives it. A form without an action posts to the
	 * page's own address.
	 *
	 * @return array{action: string, fields: array<string, string>}
	 */
	public function formWith(string $field = '', string $id = ''): array {
		return $this->findForm(static fn(string $target, array $fields, \DOMElement $form): bool => ($field === '' || array_key_exists($field, $fields)) && ($id === '' || $form->getAttribute('id') === $id), 'with field "' . $field . '" and id "' . $id . '"');
	}

	/** @return array{action: string, fields: array<string, string>} */
	private function findForm(\Closure $matches, string $what): array {
		$dom = new \DOMDocument();
		@$dom->loadHTML('<?xml encoding="UTF-8">' . $this->body);
		foreach ($dom->getElementsByTagName('form') as $form) {
			$target = html_entity_decode($form->getAttribute('action'), ENT_QUOTES);
			$fields = [];
			$xpath = new \DOMXPath($dom);
			foreach ($xpath->query('.//input[@name]', $form) as $input) {
				$type = strtolower($input->getAttribute('type'));
				if (in_array($type, ['checkbox', 'radio'], true) && !$input->hasAttribute('checked')) {
					continue;
				}
				if (in_array($type, ['submit', 'button', 'file'], true)) {
					continue;
				}
				$fields[$input->getAttribute('name')] = $input->getAttribute('value');
			}
			foreach ($xpath->query('.//select[@name]', $form) as $select) {
				$chosen = $xpath->query('.//option[@selected]', $select)->item(0) ?? $xpath->query('.//option', $select)->item(0);
				$fields[$select->getAttribute('name')] = $chosen ? $chosen->getAttribute('value') : '';
			}
			foreach ($xpath->query('.//textarea[@name]', $form) as $textarea) {
				$fields[$textarea->getAttribute('name')] = $textarea->textContent;
			}
			if (!$matches($target, $fields, $form)) {
				continue;
			}

			return ['action' => $target === '' ? $this->url : self::absolute($target, $this->url), 'fields' => $fields];
		}

		throw new \RuntimeException('no form ' . $what . ' on ' . $this->url . ' (' . $this->status . '): ' . substr(strip_tags($this->body), 0, 800));
	}

	/** The page's text, tags removed and space collapsed. */
	public function text(): string {
		return trim((string)preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($this->body), ENT_QUOTES)));
	}

	private static function absolute(string $url, string $base): string {
		if (preg_match('#\A[a-z][a-z0-9+.-]*://#i', $url)) {
			return $url;
		}
		$parts = parse_url($base);
		if (str_starts_with($url, '//')) {
			return $parts['scheme'] . ':' . $url;
		}
		$origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
		if (str_starts_with($url, '/')) {
			return $origin . $url;
		}
		$dir = isset($parts['path']) ? preg_replace('#/[^/]*\z#', '/', $parts['path']) : '/';

		return $origin . $dir . $url;
	}
}
