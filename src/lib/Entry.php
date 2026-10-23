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
 * The API's entry (`GET {api}`) as the store's key sees it: its links and
 * the forms the key may use. Monokulo lets a client keep it per key for its
 * `Cache-Control: max-age` (an hour) and check it again with its `ETag`, so
 * making an order is usually one request.
 */
final class Entry
{
    /**
     * @param array<string, mixed> $body
     * @param int $expiresAt Unix seconds; until then it is used without asking
     */
    public function __construct(
        public readonly array $body,
        public readonly string $etag,
        public readonly int $expiresAt,
    ) {
    }

    /** The entry as kept, or null when `json` isn't one. */
    public static function fromJson(string $json, string $etag, int $expiresAt): ?self
    {
        $body = json_decode($json, true);

        return is_array($body) ? new self($body, $etag, $expiresAt) : null;
    }

    public function json(): string
    {
        return (string) json_encode($this->body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function fresh(int $now): bool
    {
        return $now < $this->expiresAt;
    }

    /**
     * The form `name` (`create-order`, `send-logs`), or null when the entry
     * doesn't offer it to this key.
     *
     * @return array<string, mixed>|null
     */
    public function form(string $name): ?array
    {
        $form = $this->body['_templates'][$name] ?? null;

        return is_array($form) ? $form : null;
    }

    /** The same entry, kept for longer (a `304 Not Modified`). */
    public function keptUntil(int $expiresAt): self
    {
        return new self($this->body, $this->etag, $expiresAt);
    }
}
