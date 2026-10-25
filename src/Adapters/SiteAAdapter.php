<?php

declare(strict_types=1);

namespace SyncGuard\Adapters;

use SyncGuard\Http\CurlClient;

/**
 * Class SiteAAdapter
 *
 * Channel adapter implementation for booking partner "Site A".
 * Prepares JSON payloads, signs requests via HMAC-SHA256, and maps responses to PushResult.
 */
class SiteAAdapter implements ChannelAdapter
{
    private readonly CurlClient $httpClient;
    private readonly string $sharedSecret;

    public function __construct(
        ?CurlClient $httpClient = null,
        ?string $sharedSecret = null
    ) {
        if ($httpClient === null || $sharedSecret === null) {
            $config = require dirname(__DIR__, 2) . '/config/config.php';
            $this->httpClient = $httpClient ?? new CurlClient(
                connectTimeout: (int) ($config['adapter']['connect_timeout'] ?? 2),
                timeout: (int) ($config['adapter']['timeout'] ?? 5)
            );
            $this->sharedSecret = $sharedSecret ?? (string) ($config['mock']['shared_secret'] ?? 'dev_mock_shared_secret_7c9f8a2b4e6d1c3a');
        } else {
            $this->httpClient = $httpClient;
            $this->sharedSecret = $sharedSecret;
        }
    }

    /**
     * Dispatch an update to Site A's mock endpoint.
     *
     * @param array<string, mixed> $update
     * @param array<string, mixed> $channel
     * @return PushResult
     */
    public function push(array $update, array $channel): PushResult
    {
        $endpointUrl = (string) ($channel['endpoint_url'] ?? 'http://web/mock/site_a.php');

        // Build canonical JSON payload
        $payload = [
            'idempotency_key' => (string) ($update['idempotency_key'] ?? ''),
            'correlation_id'  => (string) ($update['correlation_id'] ?? ''),
            'room_id'         => (int) ($update['room_id'] ?? 0),
            'date_from'       => (string) ($update['date_from'] ?? ''),
            'date_to'         => (string) ($update['date_to'] ?? ''),
            'available_units' => isset($update['available_units']) && $update['available_units'] !== null ? (int) $update['available_units'] : null,
            'price'           => isset($update['price']) && $update['price'] !== null ? (float) $update['price'] : null,
        ];

        $rawJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($rawJson === false) {
            return PushResult::httpError(0, null, 'Failed to serialize update payload to JSON');
        }

        // Generate HMAC-SHA256 cryptographic signature
        $signature = 'sha256=' . hash_hmac('sha256', $rawJson, $this->sharedSecret);

        $headers = [
            'Content-Type: application/json',
            'X-Idempotency-Key: ' . $payload['idempotency_key'],
            'X-Correlation-Id: ' . $payload['correlation_id'],
            'X-Signature: ' . $signature,
        ];

        // Execute dispatch via cURL
        $httpResult = $this->httpClient->postJson($endpointUrl, $rawJson, $headers);

        // 1. Check for transport-level timeout
        if ($httpResult->isTimeout()) {
            return PushResult::timeout(
                durationMs: $httpResult->durationMs,
                message: 'Dispatch timed out: ' . ($httpResult->curlErrorMessage ?: 'Operation timed out')
            );
        }

        // 2. Check for transport or HTTP error status (outside 2xx)
        if (!$httpResult->is2xx()) {
            $msg = $httpResult->curlErrorMessage !== ''
                ? $httpResult->curlErrorMessage
                : sprintf('HTTP error %d received from channel', $httpResult->statusCode ?? 0);

            return PushResult::httpError(
                durationMs: $httpResult->durationMs,
                httpStatus: $httpResult->statusCode,
                message: $msg
            );
        }

        // 3. Inspect response body content
        /**
         * WHY READING THE RESPONSE BODY MATTERS (HTTP 200 vs ok: true):
         * Many third-party OTA gateways return HTTP 200 OK simply indicating that their web server
         * received the request, but embed application-level rejections inside the JSON body
         * (e.g. {"ok": false, "error": "Rate below contract minimum"}).
         * A channel adapter must therefore inspect the decoded JSON payload for {"ok": true}
         * rather than naively relying on the HTTP 200 status code alone.
         */
        $decoded = json_decode((string) $httpResult->body, true);
        if (!is_array($decoded) || !isset($decoded['ok']) || $decoded['ok'] !== true) {
            $rejectionReason = is_array($decoded) && isset($decoded['error'])
                ? (string) $decoded['error']
                : 'Site A rejected payload: response body missing ok=true';

            return PushResult::rejected(
                durationMs: $httpResult->durationMs,
                httpStatus: $httpResult->statusCode,
                message: $rejectionReason
            );
        }

        // 4. Successful delivery confirmed
        $statusMsg = isset($decoded['status']) ? (string) $decoded['status'] : 'accepted';
        return PushResult::success(
            durationMs: $httpResult->durationMs,
            httpStatus: $httpResult->statusCode,
            message: 'Site A confirmed delivery: ' . $statusMsg
        );
    }
}
