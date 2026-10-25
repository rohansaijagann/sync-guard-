<?php

declare(strict_types=1);

namespace SyncGuard\Http;

use Throwable;

/**
 * Class CurlClient
 *
 * Lightweight, non-throwing HTTP client designed for channel adapter dispatch.
 * Enforces strict timeouts and returns normalized HttpResult objects.
 */
class CurlClient
{
    /**
     * @param int $connectTimeout Maximum seconds allowed to establish TCP connection (default 2s)
     * @param int $timeout Maximum seconds allowed for the entire HTTP request lifecycle (default 5s)
     */
    public function __construct(
        private readonly int $connectTimeout = 2,
        private readonly int $timeout = 5
    ) {
    }

    /**
     * Perform an HTTP POST request transmitting a JSON payload.
     *
     * WHY BOTH TIMEOUTS MATTER:
     * 1. CURLOPT_CONNECTTIMEOUT: Governs the initial TCP connection handshake with the remote OTA.
     *    If a remote endpoint is completely unreachable or firewalled, the OS default socket timeout
     *    can take up to 120-300 seconds before aborting. A short connect timeout (e.g. 2s) fails fast.
     * 2. CURLOPT_TIMEOUT: Governs the total time allowed for the full HTTP round-trip once connected.
     *    If an external server accepts the connection but hangs or trickles data slowly, an unbound
     *    request would lock the background worker thread permanently, starving the rest of the queue.
     *
     * @param string $url Target endpoint URL
     * @param string $payload Raw request body (JSON, XML, or string)
     * @param array<string> $headers Custom HTTP headers (e.g. signature, idempotency key)
     * @return HttpResult
     */
    public function post(string $url, string $payload, array $headers = []): HttpResult
    {
        return $this->postJson($url, $payload, $headers);
    }

    /**
     * Perform an HTTP POST request transmitting a JSON payload.
     */
    public function postJson(string $url, string $jsonPayload, array $headers = []): HttpResult
    {
        $start = hrtime(true);

        $ch = curl_init();
        if ($ch === false) {
            $durationMs = (int) round((hrtime(true) - $start) / 1e6);
            return new HttpResult(
                statusCode: null,
                body: null,
                curlErrorNumber: -1,
                curlErrorMessage: 'Failed to initialize cURL handle',
                durationMs: $durationMs
            );
        }

        // Standard headers
        $requestHeaders = array_merge([
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: SyncGuard-Worker/1.0',
        ], $headers);

        $options = [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $jsonPayload,
            CURLOPT_HTTPHEADER     => $requestHeaders,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_FOLLOWLOCATION => false, // Disallow unexpected 30x redirects
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        curl_setopt_array($ch, $options);

        try {
            $body = curl_exec($ch);
            $durationMs = (int) round((hrtime(true) - $start) / 1e6);

            $curlErrorNum = curl_errno($ch);
            $curlErrorMsg = curl_error($ch);
            $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            curl_close($ch);

            return new HttpResult(
                statusCode: $statusCode > 0 ? (int) $statusCode : null,
                body: is_string($body) ? $body : null,
                curlErrorNumber: $curlErrorNum,
                curlErrorMessage: $curlErrorMsg,
                durationMs: $durationMs
            );
        } catch (Throwable $e) {
            $durationMs = (int) round((hrtime(true) - $start) / 1e6);
            if ($ch instanceof \CurlHandle) {
                curl_close($ch);
            }

            return new HttpResult(
                statusCode: null,
                body: null,
                curlErrorNumber: -1,
                curlErrorMessage: $e->getMessage(),
                durationMs: $durationMs
            );
        }
    }
}
