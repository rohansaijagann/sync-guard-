<?php

declare(strict_types=1);

namespace SyncGuard\Adapters;

/**
 * Class PushResult
 *
 * Immutable value object encapsulating the outcome of an OTA push dispatch attempt.
 * Normalizes HTTP status codes, latencies, and diagnostic messages for telemetry logging.
 */
readonly class PushResult
{
    public string $message;

    public function __construct(
        public bool $success,
        public ?int $httpStatus,
        public PushOutcome $outcome,
        string $message,
        public int $durationMs
    ) {
        // Enforce maximum 255 characters to match `sync_attempts.message` column width
        $this->message = mb_substr($message, 0, 255, 'UTF-8');
    }

    /**
     * Create a successful delivery result.
     */
    public static function success(int $durationMs, ?int $httpStatus = 200, string $message = 'Delivery accepted'): self
    {
        return new self(
            success: true,
            httpStatus: $httpStatus,
            outcome: PushOutcome::Success,
            message: $message,
            durationMs: $durationMs
        );
    }

    /**
     * Create a timeout delivery result.
     */
    public static function timeout(int $durationMs, string $message = 'Connection or response timed out'): self
    {
        return new self(
            success: false,
            httpStatus: null,
            outcome: PushOutcome::Timeout,
            message: $message,
            durationMs: $durationMs
        );
    }

    /**
     * Create an HTTP or transport error delivery result.
     */
    public static function httpError(int $durationMs, ?int $httpStatus, string $message): self
    {
        return new self(
            success: false,
            httpStatus: $httpStatus,
            outcome: PushOutcome::HttpError,
            message: $message,
            durationMs: $durationMs
        );
    }

    /**
     * Create an endpoint refusal / business rejection result (e.g. HTTP 200 but ok: false).
     */
    public static function rejected(int $durationMs, ?int $httpStatus, string $message): self
    {
        return new self(
            success: false,
            httpStatus: $httpStatus,
            outcome: PushOutcome::Rejected,
            message: $message,
            durationMs: $durationMs
        );
    }
}
