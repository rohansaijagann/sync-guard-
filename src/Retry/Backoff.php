<?php

declare(strict_types=1);

namespace SyncGuard\Retry;

/**
 * Class Backoff
 *
 * Evaluates exponential backoff delays with randomized jitter and enforces maximum retry limits.
 * Pure logic component with zero database dependencies.
 *
 * WHY BACKOFF MATTERS:
 * When an external booking channel (OTA) encounters high latency or crashes under load, immediately
 * retrying at short fixed intervals hammers the struggling partner and exacerbates the outage.
 * Progressively increasing delays (1m -> 5m -> 15m -> 1h) allows remote systems time to recover.
 *
 * WHY JITTER IS CRITICAL:
 * If a network disruption causes 100 queued jobs to fail simultaneously, retrying after an exact fixed
 * duration causes all 100 requests to hit the OTA in the exact same second ("thundering herd" storm).
 * Adding +/-10% random jitter disperses retry dispatches evenly across a time window.
 *
 * WHY A HARD MAXIMUM EXISTS:
 * Some failures are permanent (e.g. invalid room mapping, uncontracted rate codes, decommissioned APIs).
 * Retrying indefinitely consumes worker capacity and clutters database tables.
 * Capping attempts at 5 marks the job as 'dead', requiring human staff review.
 */
class Backoff
{
    /**
     * Maximum delivery attempts permitted before marking a job as 'dead'.
     */
    public const MAX_ATTEMPTS = 5;

    /**
     * Canonical retry schedule (delay in seconds keyed by attempt count).
     */
    public const SCHEDULE = [
        1 => 60,    // 1 minute
        2 => 300,   // 5 minutes
        3 => 900,   // 15 minutes
        4 => 3600,  // 1 hour
    ];

    /**
     * @param int $jitterPercent Percentage (+/-) applied to the base delay (default 10%)
     * @param (callable(): float)|null $randomSource Optional custom RNG returning 0.0 to 1.0
     */
    public function __construct(
        private readonly int $jitterPercent = 10,
        private readonly mixed $randomSource = null
    ) {
    }

    /**
     * Calculate retry decision based on the failure attempt count.
     * Supports both instance and static invocations for testing and operational flexibility.
     *
     * @param int $attemptCount Number of attempts including the one that just failed
     * @param int|null $jitterPercent Optional jitter percentage override
     * @param (callable(): float)|null $randomSource Optional RNG callable override returning float 0.0 - 1.0
     * @return RetryDecision
     */
    public static function decide(
        int $attemptCount,
        ?int $jitterPercent = null,
        ?callable $randomSource = null
    ): RetryDecision {
        // If attempt reaches or exceeds maximum threshold, transition to 'dead'
        if ($attemptCount >= self::MAX_ATTEMPTS) {
            return RetryDecision::dead();
        }

        $effectiveAttempt = max(1, $attemptCount);
        $baseDelay = self::SCHEDULE[$effectiveAttempt];

        // Resolve jitter percentage: parameter override > config > default 10
        if ($jitterPercent === null) {
            $config = @require dirname(__DIR__, 2) . '/config/config.php';
            $jitterPercent = (int) ($config['retry']['jitter_percent'] ?? 10);
        }

        // With zero jitter, return the exact base delay
        if ($jitterPercent <= 0) {
            return RetryDecision::retry($baseDelay);
        }

        // Obtain float between 0.0 and 1.0 from injectable source or secure PRNG
        $randFloat = $randomSource !== null
            ? (float) $randomSource()
            : (random_int(0, 1000000) / 1000000.0);

        // Clamp random float to [0.0, 1.0]
        $randFloat = max(0.0, min(1.0, $randFloat));

        /**
         * Jitter calculation (+/- jitterPercent%):
         * Lower bound (randFloat = 0.0) -> baseDelay * (1 - jitterPercent / 100)
         * Upper bound (randFloat = 1.0) -> baseDelay * (1 + jitterPercent / 100)
         * Center (randFloat = 0.5)      -> baseDelay * 1.0
         */
        $fraction = $jitterPercent / 100.0;
        $multiplier = (1.0 - $fraction) + (2.0 * $fraction * $randFloat);

        $delay = (int) round($baseDelay * $multiplier);

        // Invariant: Never allow jitter to produce a delay under 1 second
        return RetryDecision::retry(max(1, $delay));
    }

    /**
     * Instance convenience method delegating to decide() using constructor properties.
     */
    public function calculate(int $attemptCount): RetryDecision
    {
        return self::decide($attemptCount, $this->jitterPercent, $this->randomSource);
    }
}
