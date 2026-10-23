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

final class HttpResponse
{
    /**
     * @param array<string, string> $headers by lower-case name
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = [],
    ) {
    }

    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }

    /** How long the answer may be kept, in seconds: its `Cache-Control: max-age`, or 0. */
    public function maxAge(): int
    {
        $control = strtolower($this->header('Cache-Control'));
        if (str_contains($control, 'no-store') || str_contains($control, 'no-cache')) {
            return 0;
        }

        return preg_match('/(?:\A|[\s,])max-age=(\d+)/', $control, $m) ? (int) $m[1] : 0;
    }
}
