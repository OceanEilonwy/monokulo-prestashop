<?php

/**
 * Monokulo for PrestaShop
 *
 * @author    Monokulo
 * @copyright Monokulo
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 */

namespace Monokulo\PrestaShop;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * One W3C trace per shop request. Every call to Monokulo carries it as
 * `traceparent`, and every log line names it, so a merchant can match their
 * log to Monokulo's. A webhook's own `traceparent` is joined instead of
 * starting a new trace.
 */
final class Trace
{
    private string $id;

    public function __construct(?string $id = null)
    {
        $this->id = $id ?? bin2hex(random_bytes(16));
    }

    public function id(): string
    {
        return $this->id;
    }

    /**
     * Joins the trace an incoming `traceparent` (version 00) names. False,
     * and the trace unchanged, when the header is absent or malformed.
     */
    public function adopt(string $traceparent): bool
    {
        if (!preg_match('/\A00-([0-9a-f]{32})-([0-9a-f]{16})-[0-9a-f]{2}\z/', trim($traceparent), $parts)) {
            return false;
        }
        if ($parts[1] === str_repeat('0', 32) || $parts[2] === str_repeat('0', 16)) {
            return false;
        }
        $this->id = $parts[1];

        return true;
    }

    /** A `traceparent` for one outgoing call: this trace, a fresh span. */
    public function traceparent(): string
    {
        return '00-' . $this->id . '-' . bin2hex(random_bytes(8)) . '-01';
    }
}
