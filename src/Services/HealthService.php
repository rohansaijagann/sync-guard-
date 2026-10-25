<?php

declare(strict_types=1);

namespace SyncGuard\Services;

/**
 * HealthService
 *
 * Encapsulates channel reliability rules, status labeling thresholds, and metric formulas.
 *
 * WHY A ROLLING WINDOW (e.g. 50 attempts) INSTEAD OF ALL-TIME METRICS:
 * In hotel distribution, an external OTA might experience an outage or severe degradation on Tuesday,
 * but recover completely by Wednesday morning. If health metrics were calculated across all-time history,
 * thousands of historical failures would permanently depress the partner's success rate for weeks,
 * falsely signaling to staff that the channel is still degraded. A rolling window of the last 50 attempts
 * ensures that once a channel recovers and processes 50 clean attempts, its health numbers return to 100%.
 *
 * WHY AVERAGE LATENCY ONLY COUNTS SUCCESSFUL ATTEMPTS:
 * Failed attempts (such as HTTP 500/503 errors or 5-second cURL timeouts) are already penalized in the
 * success_rate calculation. Including 5,000ms timeouts in the latency calculation would artificially
 * distort the "typical speed" of the partner's functional API. The average response time answers the question:
 * "When this booking channel is successfully accepting updates, how fast is it responding?"
 */
class HealthService
{
    /**
     * Default rolling evaluation window (number of most recent delivery attempts).
     */
    public const DEFAULT_WINDOW_ATTEMPTS = 50;

    /**
     * Consecutive failure thresholds.
     * 5 consecutive failures indicates partner is completely offline ('down').
     * 2 consecutive failures indicates partner is struggling ('degraded').
     */
    public const CONSECUTIVE_FAILURES_DOWN = 5;
    public const CONSECUTIVE_FAILURES_DEGRADED = 2;

    /**
     * Success rate threshold for degraded performance (< 90.0%).
     */
    public const SUCCESS_RATE_DEGRADED_THRESHOLD = 90.0;

    /**
     * Required consecutive successful deliveries to prove channel recovery (RECOVERY_STREAK = 5).
     */
    public const RECOVERY_STREAK = 5;

    /**
     * Determine the health status label for a channel based on evaluation thresholds.
     *
     * WHY A STREAK AND NOT THE SUCCESS RATE ALONE:
     * The rolling 50-attempt success rate looks backward over a broad window of past history.
     * When an outage is fixed, old failures remain in that window for dozens of deliveries,
     * which would falsely keep the channel labeled 'degraded' long after it is healthy.
     * A recent consecutive success streak (e.g. 5 successful deliveries in a row) proves that
     * the site is working reliably NOW, allowing it to transition to 'recovering' and then 'healthy'.
     *
     * Evaluation order (strictly prioritized):
     * 1. 'no_data'    - If no attempt has ever happened.
     * 2. 'down'       - If consecutive_failures >= 5.
     * 3. 'degraded'   - If consecutive_failures >= 2, OR (consecutive_failures >= 1 AND success_rate < 90.0).
     * 4. 'recovering' - If success_rate < 90.0 (where consecutive_failures is 0) and recentSuccessStreak < 5.
     * 5. 'healthy'    - All thresholds met cleanly (or streak >= 5).
     *
     * @param bool  $hasData             True if at least one attempt has ever been recorded.
     * @param int   $consecutiveFailures Number of consecutive failed attempts from the newest attempt.
     * @param float $successRate         Percentage of successful attempts in the evaluation window (0.00 to 100.00).
     * @param int   $recentSuccessStreak Number of consecutive successful attempts at the end of channel attempt history (0 to 5).
     * @return string One of 'no_data', 'down', 'degraded', 'recovering', 'healthy'.
     */
    public static function labelFor(
        bool $hasData,
        int $consecutiveFailures,
        float $successRate,
        int $recentSuccessStreak = 0
    ): string {
        if (!$hasData) {
            return 'no_data';
        }

        if ($consecutiveFailures >= self::CONSECUTIVE_FAILURES_DOWN) {
            return 'down';
        }

        if (
            $consecutiveFailures >= self::CONSECUTIVE_FAILURES_DEGRADED
            || ($consecutiveFailures >= 1 && $successRate < self::SUCCESS_RATE_DEGRADED_THRESHOLD)
        ) {
            return 'degraded';
        }

        if ($successRate < self::SUCCESS_RATE_DEGRADED_THRESHOLD) {
            return $recentSuccessStreak >= self::RECOVERY_STREAK ? 'healthy' : 'recovering';
        }

        return 'healthy';
    }
}
