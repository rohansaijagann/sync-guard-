<?php

declare(strict_types=1);

namespace SyncGuard\Http;

/**
 * Class HttpResult
 *
 * Immutable value object returned by CurlClient.
 * Captures raw transport metrics: HTTP status, response body, cURL error codes, and elapsed time.
 */
readonly class HttpResult
{
    public function __construct(
        public ?int $statusCode,
        public ?string $body,
        public int $curlErrorNumber,
        public string $curlErrorMessage,
        public int $durationMs
    ) {
    }

    /**
     * Check if the request failed due to a transport timeout.
     */
    public function isTimeout(): bool
    {
        // CURLE_OPERATION_TIMEDOUT is error 28
        return $this->curlErrorNumber === 28
            || str_contains(strtolower($this->curlErrorMessage), 'timed out')
            || str_contains(strtolower($this->curlErrorMessage), 'timeout');
    }

    /**
     * Check if the HTTP status code falls in the 2xx Success range.
     */
    public function is2xx(): bool
    {
        return $this->statusCode !== null && $this->statusCode >= 200 && $this->statusCode < 300;
    }
}
