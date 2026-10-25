<?php

declare(strict_types=1);

namespace SyncGuard\Retry;

/**
 * Class RetryDecision
 *
 * Immutable value object representing the outcome of a retry delay evaluation.
 * Encapsulates whether a job should transition to 'dead' or wait for a calculated delay.
 */
readonly class RetryDecision
{
    public function __construct(
        public bool $isDead,
        public int $delaySeconds
    ) {
    }

    /**
     * Factory for an active retry attempt with scheduled delay.
     */
    public static function retry(int $delaySeconds): self
    {
        return new self(isDead: false, delaySeconds: max(1, $delaySeconds));
    }

    /**
     * Factory for a terminal 'dead' state where all retry attempts have been exhausted.
     */
    public static function dead(): self
    {
        return new self(isDead: true, delaySeconds: 0);
    }
}
