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
 * gave it and follows its links and forms, so it hard-codes no address
 * past the entry.
 */
final class Client
{
    private const HAL = 'application/prs.hal-forms+json';

    public function __construct(
        private readonly Trace $trace,
    ) {
    }

    /**
     * `POST {address}/plugins/connect/prestashop/finish`: redeems the single-use token
     * the connect flow handed back, registers `webhookUrl`, and returns the
     * store's credentials. `api`, the API's entry, comes back as an
     * absolute URL on the address the token was redeemed at.
     *
     * @return array{public_key: string, secret_token: string, api: string, webhook_signing_secret: string}
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

        return [
            'public_key' => $data['public_key'],
            'secret_token' => $data['secret_token'],
            'api' => Url::resolve($url, $data['api']),
            'webhook_signing_secret' => $data['webhook_signing_secret'],
        ];
    }

    /**
     * Makes an order with the entry's `create-order` form, and returns its
     * id and `mk:checkout` link (absolute), where the customer pays.
     *
     * @param array<string, mixed> $body see OrderRequest
     *
     * @return array{order_id: string, checkout: string}
     *
     * @throws ApiError|TransportError
     */
    public function createOrder(Connection $connection, array $body): array
    {
        $entryResponse = $this->send('GET', $connection->api, $this->headers(null, $connection), null, 15);
        $entry = $this->decode($entryResponse, 200);
        $form = $entry['_templates']['create-order'] ?? null;
        if (!is_array($form)) {
            // The entry leaves out the forms a key may not use.
            throw new ApiError(401, 'the API entry offers this key no create-order form: ' . $entryResponse->body);
        }
        $self = $entry['_links']['self']['href'] ?? '';
        $target = Url::resolve($connection->api, is_string($form['target'] ?? null) ? $form['target'] : (is_string($self) ? $self : ''));
        $method = is_string($form['method'] ?? null) ? strtoupper($form['method']) : 'POST';
        $contentType = is_string($form['contentType'] ?? null) ? $form['contentType'] : 'application/json';

        $response = $this->send($method, $target, $this->headers($contentType, $connection), (string) json_encode($body), 30);
        $order = $this->decode($response, 201);
        $orderId = $order['order_id'] ?? null;
        $checkout = $order['_links']['mk:checkout']['href'] ?? null;
        if (!is_string($orderId) || $orderId === '' || !is_string($checkout) || $checkout === '') {
            throw new ApiError($response->status, 'missing order_id or its mk:checkout link: ' . $response->body);
        }

        return ['order_id' => $orderId, 'checkout' => Url::resolve($target, $checkout)];
    }

    /**
     * `POST {entry}/logs`: the shop's warnings and errors, for the merchant
     * to read in Monokulo. Best effort: a failure here is ignored.
     *
     * @param list<array{level: string, message: string, trace_id: string}> $entries
     */
    public function forwardLogs(Connection $connection, array $entries): void
    {
        if (!$entries) {
            return;
        }
        try {
            $this->send('POST', $connection->logsUrl(), $this->headers('application/json', $connection), (string) json_encode(['entries' => $entries]), 3);
        } catch (TransportError) {
            // Nothing to do: these are reports about other failures.
        }
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
        $curl = curl_init($url);
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeoutSeconds),
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
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

        return new HttpResponse((int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), (string) $answer);
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
     * ApiError with the problem's `detail` (or the connect flow's `error`).
     *
     * @return array<string, mixed>
     *
     * @throws ApiError
     */
    private function decode(HttpResponse $response, int $success): array
    {
        $data = json_decode($response->body, true);
        if ($response->status !== $success) {
            $detail = $response->body;
            $type = '';
            if (is_array($data)) {
                $detail = match (true) {
                    isset($data['detail']) && is_string($data['detail']) => $data['detail'],
                    isset($data['title']) && is_string($data['title']) => $data['title'],
                    isset($data['error']) && is_string($data['error']) => $data['error'],
                    default => $detail,
                };
                $type = isset($data['type']) && is_string($data['type']) ? $data['type'] : '';
            }

            throw new ApiError($response->status, $detail, $type);
        }
        if (!is_array($data)) {
            throw new ApiError($response->status, 'not JSON: ' . $response->body);
        }

        return $data;
    }
}
