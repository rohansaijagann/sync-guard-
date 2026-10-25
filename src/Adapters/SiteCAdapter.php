<?php

declare(strict_types=1);

namespace SyncGuard\Adapters;

use SyncGuard\Http\CurlClient;

/**
 * Class SiteCAdapter
 *
 * Channel adapter implementation for booking partner "Site C".
 * Uses JSON payloads with HMAC-SHA256 signatures, handling unreliable transport responses
 * and application-level business rejections.
 */
class SiteCAdapter implements ChannelAdapter
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
     * Dispatch an update to Site C.
     *
     * @param array<string, mixed> $update
     * @param array<string, mixed> $channel
     * @return PushResult
     */
    public function push(array $update, array $channel): PushResult
    {
        $endpointUrl = (string) ($channel['endpoint_url'] ?? 'http://web/mock/site_c.php');

        $payload = [
            'idempotency_key' => (string) ($update['idempotency_key'] ?? ''),
            'correlation_id'  => (string) ($update['correlation_id'] ?? ''),
            'room_id'         => (int) ($update['room_id'] ?? 0),
            'room_name'       => (string) ($update['room_name'] ?? ''),
            'date_from'       => (string) ($update['date_from'] ?? ''),
            'date_to'         => (string) ($update['date_to'] ?? ''),
            'available_units' => isset($update['available_units']) && $update['available_units'] !== null ? (int) $update['available_units'] : null,
            'price'           => isset($update['price']) && $update['price'] !== null ? (float) $update['price'] : null,
        ];

        $rawJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($rawJson === false) {
            return PushResult::httpError(0, null, 'Failed to serialize update payload to JSON');
        }

        $signature = 'sha256=' . hash_hmac('sha256', $rawJson, $this->sharedSecret);

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Idempotency-Key: ' . $payload['idempotency_key'],
            'X-Correlation-Id: ' . $payload['correlation_id'],
            'X-Signature: ' . $signature,
        ];

        $httpResult = $this->httpClient->postJson($endpointUrl, $rawJson, $headers);

        // 1. Timeout check
        if ($httpResult->isTimeout()) {
            return PushResult::timeout(
                durationMs: $httpResult->durationMs,
                message: 'Site C timed out: ' . ($httpResult->curlErrorMessage ?: 'Operation timed out')
            );
        }

        // 2. HTTP error check (e.g. 500 Internal Error, 503 Unavailable)
        if (!$httpResult->is2xx()) {
            $msg = $httpResult->curlErrorMessage !== ''
                ? $httpResult->curlErrorMessage
                : sprintf('HTTP error %d received from Site C', $httpResult->statusCode ?? 0);

            return PushResult::httpError(
                durationMs: $httpResult->durationMs,
                httpStatus: $httpResult->statusCode,
                message: $msg
            );
        }

        // 3. Inspect body: verify ok: true
        $decoded = json_decode((string) $httpResult->body, true);
        if (!is_array($decoded) || !isset($decoded['ok']) || $decoded['ok'] !== true) {
            $rejectionMsg = is_array($decoded) && isset($decoded['error'])
                ? (string) $decoded['error']
                : 'Site C rejected update: missing ok=true in body';

            return PushResult::rejected(
                durationMs: $httpResult->durationMs,
                httpStatus: $httpResult->statusCode,
                message: $rejectionMsg
            );
        }

        // 4. Success confirmed
        $statusMsg = isset($decoded['status']) ? (string) $decoded['status'] : 'accepted';
        return PushResult::success(
            durationMs: $httpResult->durationMs,
            httpStatus: $httpResult->statusCode,
            message: 'Site C confirmed delivery: ' . $statusMsg
        );
    }
}
