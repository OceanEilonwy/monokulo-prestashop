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
 * The calls the shop's server makes to Monokulo: redeem a connect token,
 * create an order through Monokulo's HTTP API, and (when the merchant
 * turned it on) forward its warnings and errors.
 *
 * The API is HAL-FORMS: the client starts at the entry the connect flow
 * gave it and follows its forms, so it builds no address past the entry.
 * It keeps the entry for as long as Monokulo says (EntryStore), and reads
 * it again when a form from it is refused with 401, 404 or 405.
 */
final class Client
{
    private const HAL = 'application/prs.hal-forms+json';
    /** Answers that mean the kept entry may be out of date: the key changed, or the API moved on. */
    private const STALE = [401, 404, 405];

    /** @var array<string, Entry> the entry as read during this request, by key */
    private array $read = [];

    public function __construct(
        private readonly Trace $trace,
        private readonly ?EntryStore $entries = null,
    ) {
    }

    /**
     * `POST {address}/plugins/connect/prestashop/finish`: redeems the single-use token
     * the connect flow handed back, registers `webhookUrl`, and returns the
     * store's credentials. `api`, the API's entry, comes back as an
     * absolute URL on the address the token was redeemed at (with the path
     * a Monokulo under a path has); `onion_api`, the entry on Monokulo's
     * onion address, is null when it has none.
     *
     * @return array{public_key: string, secret_token: string, api: string, onion_api: string|null, webhook_signing_secret: string}
     *
     * @throws ApiError|TransportError
     */
    public function finishConnect(string $address, string $token, string $webhookUrl): array
    {
        $url = rtrim($address, '/') . '/plugins/connect/' . Plugin::SLUG . '/finish';
        $response = $this->send('POST', $url, $this->headers('application/json'), (string) json_encode([
            'token' => $token,
            'webhook_url' => $webhookUrl,
            'name' => Plugin::NAME,
        ]), 30);
        $data = $this->decode($response, 200);
        foreach (['public_key', 'secret_token', 'api', 'webhook_signing_secret'] as $key) {
            if (!isset($data[$key]) || !is_string($data[$key]) || $data[$key] === '') {
                throw new ApiError($response->status, 'missing ' . $key . ': ' . $response->body);
            }
        }
        $onion = $data['onion_api'] ?? null;

        return [
            'public_key' => $data['public_key'],
            'secret_token' => $data['secret_token'],
            'api' => Url::resolve($url, $data['api']),
            'onion_api' => is_string($onion) && $onion !== '' ? $onion : null,
            'webhook_signing_secret' => $data['webhook_signing_secret'],
        ];
    }

    /**
     * Makes an order with the entry's `create-order` form. Returns its id
     * and links, absolute: `checkout` (`mk:checkout`), where the customer
     * pays; `onion_checkout` (`mk:onion-checkout`), the same page on
     * Monokulo's onion address, null when it has none; and `dashboard`
     * (`mk:dashboard-order`), the order's page for the merchant.
     *
     * @param array<string, mixed> $body see OrderRequest
     *
     * @return array{order_id: string, checkout: string, onion_checkout: string|null, dashboard: string|null}
     *
     * @throws ApiError|TransportError
     */
    public function createOrder(Connection $connection, array $body): array
    {
        [$target, $response] = $this->submit($connection, 'create-order', $body, 30);
        $order = $this->decode($response, 201);
        $orderId = $order['order_id'] ?? null;
        $link = static function (string $rel) use ($order, $target): ?string {
            $href = $order['_links'][$rel]['href'] ?? null;

            return is_string($href) && $href !== '' ? Url::resolve($target, $href) : null;
        };
        $checkout = $link('mk:checkout');
        if (!is_string($orderId) || $orderId === '' || $checkout === null) {
            throw new ApiError($response->status, 'missing order_id or its mk:checkout link: ' . $response->body);
        }

        return [
            'order_id' => $orderId,
            'checkout' => $checkout,
            'onion_checkout' => $link('mk:onion-checkout'),
            'dashboard' => $link('mk:dashboard-order'),
        ];
    }

    /**
     * The shop's warnings and errors, for the merchant to read in Monokulo,
     * with the entry's `send-logs` form. Best effort: nothing is sent when
     * the entry doesn't offer the form, and a failure here is ignored.
     *
     * @param list<array{level: string, message: string, trace_id: string}> $entries
     */
    public function forwardLogs(Connection $connection, array $entries): void
    {
        if (!$entries) {
            return;
        }
        try {
            $this->submit($connection, 'send-logs', ['entries' => $entries], 3);
        } catch (ApiError|TransportError) {
            // Nothing to do: these are reports about other failures.
        }
    }

    /**
     * Fills in the entry's form `name` with `body`. When the form's answer
     * says the kept entry may be out of date (401, 404, 405), the entry is
     * read again and, if it changed, the form sent once more from it.
     *
     * @param array<string, mixed> $body
     *
     * @return array{string, HttpResponse} where it went, and the answer
     *
     * @throws ApiError|TransportError
     */
    private function submit(Connection $connection, string $name, array $body, int $timeoutSeconds): array
    {
        $entry = $this->entry($connection, false);
        $sent = $this->sendForm($connection, $entry, $name, $body, $timeoutSeconds);
        if (in_array($sent[1]->status, self::STALE, true)) {
            $again = $this->entry($connection, true);
            if ($again->body !== $entry->body) {
                $sent = $this->sendForm($connection, $again, $name, $body, $timeoutSeconds);
            }
        }

        return $sent;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{string, HttpResponse}
     *
     * @throws ApiError|TransportError
     */
    private function sendForm(Connection $connection, Entry $entry, string $name, array $body, int $timeoutSeconds): array
    {
        $form = $entry->form($name);
        $target = $form['target'] ?? null;
        if ($form === null || !is_string($target) || $target === '') {
            throw new ApiError(0, 'the API entry offers this key no ' . $name . ' form');
        }
        $target = Url::resolve($connection->api, $target);
        $method = is_string($form['method'] ?? null) ? strtoupper($form['method']) : 'POST';
        $contentType = is_string($form['contentType'] ?? null) ? $form['contentType'] : 'application/json';

        return [$target, $this->send($method, $target, $this->headers($contentType, $connection), (string) json_encode($body), $timeoutSeconds)];
    }

    /**
     * The connection's API entry: the kept one while it is fresh (unless
     * `again`), else read with `If-None-Match`, and kept for as long as its
     * `Cache-Control` says.
     *
     * @throws ApiError|TransportError
     */
    private function entry(Connection $connection, bool $again): Entry
    {
        $now = time();
        $kept = $this->read[$connection->secretToken] ?? $connection->entry;
        if ($kept && !$again && $kept->fresh($now)) {
            return $kept;
        }
        $headers = $this->headers(null, $connection);
        if ($kept && $kept->etag !== '') {
            $headers['If-None-Match'] = $kept->etag;
        }
        $response = $this->send('GET', $connection->api, $headers, null, 15);
        if ($kept && $response->status === 304) {
            $entry = $kept->keptUntil($now + $response->maxAge());
        } else {
            $entry = new Entry($this->decode($response, 200), $response->header('ETag'), $now + $response->maxAge());
        }
        $this->read[$connection->secretToken] = $entry;
        $this->entries?->keepEntry($connection, $entry);

        return $entry;
    }

    /**
     * One request through PHP's curl extension (which PrestaShop requires).
     *
     * @param array<string, string> $headers
     *
     * @throws TransportError when no answer came back (refused, timed out, TLS)
     */
    private function send(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds): HttpResponse
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $received = [];
        $curl = curl_init($url);
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeoutSeconds),
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$received): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $received[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($curl, $options);
        $answer = curl_exec($curl);
        if ($answer === false) {
            $error = curl_error($curl);

            throw new TransportError($error !== '' ? $error : 'no response');
        }

        return new HttpResponse((int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), (string) $answer, $received);
    }

    /** @return array<string, string> */
    private function headers(?string $contentType, ?Connection $connection = null): array
    {
        $headers = $contentType !== null ? ['Content-Type' => $contentType] : [];
        $headers += [
            'Accept' => self::HAL . ', application/json, application/problem+json',
            'Monokulo-Client' => Plugin::client(),
            'traceparent' => $this->trace->traceparent(),
        ];
        if ($connection) {
            $headers['Authorization'] = 'Bearer ' . $connection->secretToken;
        }

        return $headers;
    }

    /**
     * The JSON object a `success` answer carries. Anything else is an
     * ApiError with the problem's `detail` (Monokulo answers every error
     * with `application/problem+json`), or the body when it isn't one.
     *
     * @return array<string, mixed>
     *
     * @throws ApiError
     */
    private function decode(HttpResponse $response, int $success): array
    {
        $data = json_decode($response->body, true);
        if ($response->status !== $success) {
            $problem = is_array($data) && isset($data['type']) && is_string($data['type']) ? $data : null;
            $detail = match (true) {
                $problem === null => $response->body,
                isset($problem['detail']) && is_string($problem['detail']) => $problem['detail'],
                isset($problem['title']) && is_string($problem['title']) => $problem['title'],
                default => $response->body,
            };

            throw new ApiError($response->status, $detail, $problem['type'] ?? '');
        }
        if (!is_array($data)) {
            throw new ApiError($response->status, 'not JSON: ' . $response->body);
        }

        return $data;
    }
}
