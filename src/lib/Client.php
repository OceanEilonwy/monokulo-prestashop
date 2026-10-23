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
 * The three calls the shop's server makes to Monokulo: redeem a connect
 * token, create an order, and (when the merchant turned it on) forward its
 * warnings and errors.
 */
final class Client
{
    public function __construct(
        private readonly Trace $trace,
    ) {
    }

    /**
     * `POST {address}/plugins/connect/prestashop/finish`: redeems the single-use token
     * the connect flow handed back, registers `webhookUrl`, and returns the
     * store's credentials.
     *
     * @return array{public_key: string, secret_token: string, endpoint: string, webhook_signing_secret: string}
     *
     * @throws ApiError|TransportError
     */
    public function finishConnect(string $address, string $token, string $webhookUrl): array
    {
        $url = rtrim($address, '/') . '/plugins/connect/' . Plugin::SLUG . '/finish';
        $response = $this->post($url, $this->headers(), (string) json_encode([
            'token' => $token,
            'webhook_url' => $webhookUrl,
            'name' => Plugin::NAME,
        ]), 30);
        $data = $this->decode($response);
        foreach (['public_key', 'secret_token', 'endpoint', 'webhook_signing_secret'] as $key) {
            if (!isset($data[$key]) || !is_string($data[$key]) || $data[$key] === '') {
                throw new ApiError($response->status, 'missing ' . $key . ': ' . $response->body);
            }
        }

        return [
            'public_key' => $data['public_key'],
            'secret_token' => $data['secret_token'],
            'endpoint' => $data['endpoint'],
            'webhook_signing_secret' => $data['webhook_signing_secret'],
        ];
    }

    /**
     * `POST {endpoint}/pay/{pk}/orders` with the store's secret key.
     *
     * @param array<string, mixed> $body see OrderRequest
     *
     * @return array{order_id: string}&array<string, mixed>
     *
     * @throws ApiError|TransportError
     */
    public function createOrder(Connection $connection, array $body): array
    {
        $headers = $this->headers() + ['Authorization' => 'Bearer ' . $connection->secretToken];
        $response = $this->post($connection->ordersUrl(), $headers, (string) json_encode($body), 30);
        $data = $this->decode($response);
        if (!isset($data['order_id']) || !is_string($data['order_id']) || $data['order_id'] === '') {
            throw new ApiError($response->status, 'missing order_id: ' . $response->body);
        }

        return $data;
    }

    /**
     * `POST {endpoint}/pay/{pk}/logs`: the shop's warnings and errors, for the
     * merchant to read in Monokulo. Best effort: a failure here is ignored.
     *
     * @param list<array{level: string, message: string, trace_id: string}> $entries
     */
    public function forwardLogs(Connection $connection, array $entries): void
    {
        if (!$entries) {
            return;
        }
        $headers = $this->headers() + ['Authorization' => 'Bearer ' . $connection->secretToken];
        try {
            $this->post($connection->logsUrl(), $headers, (string) json_encode(['entries' => $entries]), 3);
        } catch (TransportError) {
            // Nothing to do: these are reports about other failures.
        }
    }

    /**
     * One POST through PHP's curl extension (which PrestaShop requires).
     *
     * @param array<string, string> $headers
     *
     * @throws TransportError when no answer came back (refused, timed out, TLS)
     */
    private function post(string $url, array $headers, string $body, int $timeoutSeconds): HttpResponse
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeoutSeconds),
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        $answer = curl_exec($curl);
        if ($answer === false) {
            $error = curl_error($curl);

            throw new TransportError($error !== '' ? $error : 'no response');
        }

        return new HttpResponse((int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), (string) $answer);
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Monokulo-Client' => Plugin::client(),
            'traceparent' => $this->trace->traceparent(),
        ];
    }

    /**
     * The JSON object a 200 carries.
     *
     * @return array<string, mixed>
     *
     * @throws ApiError
     */
    private function decode(HttpResponse $response): array
    {
        $data = json_decode($response->body, true);
        if ($response->status !== 200) {
            $detail = is_array($data) && isset($data['error']) && is_string($data['error']) ? $data['error'] : $response->body;

            throw new ApiError($response->status, $detail);
        }
        if (!is_array($data)) {
            throw new ApiError($response->status, 'not JSON: ' . $response->body);
        }

        return $data;
    }
}
