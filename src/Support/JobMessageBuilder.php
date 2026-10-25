<?php

declare(strict_types=1);

namespace SyncGuard\Support;

use SyncGuard\Retry\Backoff;

/**
 * Class JobMessageBuilder
 *
 * Formats user-friendly, plain-language status messages for hotel staff.
 * Pure logic component with zero database access.
 *
 * WHY MESSAGES ARE BUILT ON THE SERVER AND NEVER CONTAIN TEXT FROM A BOOKING SITE:
 * Booking channels (external OTA partners) return unpredictable, arbitrary,
 * and sometimes technical or unescaped error strings (e.g. XML snippets, stack traces, HTML error pages).
 * Mixing external OTA responses directly into operational messages risks confusing staff, breaking
 * translation consistency, or introducing XSS vulnerabilities. Our plain-language messages are
 * deterministic, friendly, and owned entirely by our system. The raw OTA error text is preserved
 * in a separate `last_error` field displayed only within the expandable technical details view.
 *
 * WHY SINGLE SOURCE OF TRUTH FOR MAX ATTEMPTS:
 * Rather than hard-coding retry thresholds, we import `Backoff::MAX_ATTEMPTS`. If retry policies
 * are tuned in the future, all messages automatically reflect the updated threshold without divergence.
 */
class JobMessageBuilder
{
    /**
     * Build a human-readable explanation of what happened to a sync job.
     *
     * @param array{
     *     status?: string,
     *     channelName?: string,
     *     attemptCount?: int,
     *     maxAttempts?: int,
     *     lastOutcome?: string|null,
     *     lastHttpStatus?: int|null,
     *     nextAttemptInSeconds?: int|null,
     *     nextAttemptTimeJst?: string|null
     * } $in Input parameters describing job state
     * @return string Plain-language message
     */
    public static function build(array $in): string
    {
        $status = (string) ($in['status'] ?? 'pending');
        $channelName = (string) ($in['channelName'] ?? 'booking site');
        $attemptCount = (int) ($in['attemptCount'] ?? 0);
        $maxAttempts = isset($in['maxAttempts']) && (int) $in['maxAttempts'] > 0
            ? (int) $in['maxAttempts']
            : Backoff::MAX_ATTEMPTS;

        $lastOutcome = isset($in['lastOutcome']) ? (string) $in['lastOutcome'] : null;
        $lastHttpStatus = isset($in['lastHttpStatus']) && $in['lastHttpStatus'] !== null
            ? (int) $in['lastHttpStatus']
            : null;

        $nextAttemptInSeconds = isset($in['nextAttemptInSeconds']) && $in['nextAttemptInSeconds'] !== null
            ? (int) $in['nextAttemptInSeconds']
            : null;

        $nextAttemptTimeJst = isset($in['nextAttemptTimeJst']) && trim((string) $in['nextAttemptTimeJst']) !== ''
            ? trim((string) $in['nextAttemptTimeJst'])
            : null;

        return match ($status) {
            'pending'     => "Waiting to be sent to {$channelName}.",
            'in_progress' => "Sending to {$channelName} now.",
            'success'     => "Delivered to {$channelName}.",
            'failed'      => self::buildFailedMessage(
                $channelName,
                $attemptCount,
                $maxAttempts,
                $lastOutcome,
                $lastHttpStatus,
                $nextAttemptInSeconds,
                $nextAttemptTimeJst
            ),
            'dead'        => self::buildDeadMessage(
                $channelName,
                $attemptCount,
                $lastOutcome,
                $lastHttpStatus
            ),
            default       => "Status of {$channelName} is {$status}.",
        };
    }

    /**
     * Resolve plain-English failure cause from outcome and HTTP status code.
     */
    public static function resolveReason(string $channelName, ?string $outcome, ?int $httpStatus): string
    {
        return match ($outcome) {
            'timeout'    => "{$channelName} did not answer in time",
            'http_error' => $httpStatus !== null
                ? "{$channelName} had a server problem (HTTP {$httpStatus})"
                : "{$channelName} could not be reached",
            'rejected'   => "{$channelName} refused the update",
            default      => "{$channelName} did not accept the update",
        };
    }

    /**
     * Format a remaining delay in seconds into human duration wording.
     * Rules:
     * - Under 60 seconds: "less than a minute"
     * - Under 90 minutes: round to whole minutes ("1 minute", "5 minutes")
     * - 90 minutes and up: "about 1 hour" or "about N hours"
     */
    public static function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return 'less than a minute';
        }

        $mins = (int) round($seconds / 60);
        if ($mins < 90) {
            return $mins === 1 ? '1 minute' : "{$mins} minutes";
        }

        $hours = (int) round($seconds / 3600);
        return $hours <= 1 ? 'about 1 hour' : "about {$hours} hours";
    }

    /**
     * Build message for failed jobs scheduled for upcoming retry.
     */
    private static function buildFailedMessage(
        string $channelName,
        int $attemptCount,
        int $maxAttempts,
        ?string $lastOutcome,
        ?int $lastHttpStatus,
        ?int $nextAttemptInSeconds,
        ?string $nextAttemptTimeJst
    ): string {
        $reason = self::resolveReason($channelName, $lastOutcome, $lastHttpStatus);

        // If nextAttemptInSeconds is null or <= 0, or time string is unavailable
        if ($nextAttemptInSeconds === null || $nextAttemptInSeconds <= 0 || $nextAttemptTimeJst === null) {
            return "Attempt {$attemptCount} of {$maxAttempts} failed: {$reason}. Trying again shortly.";
        }

        $duration = self::formatDuration($nextAttemptInSeconds);

        // Clean time string if already ends in JST
        $timeStr = str_ends_with($nextAttemptTimeJst, ' JST')
            ? substr($nextAttemptTimeJst, 0, -4)
            : $nextAttemptTimeJst;

        return "Attempt {$attemptCount} of {$maxAttempts} failed: {$reason}. We will try again at {$timeStr} JST (in about {$duration}).";
    }

    /**
     * Build message for exhausted jobs in terminal 'dead' state.
     */
    private static function buildDeadMessage(
        string $channelName,
        int $attemptCount,
        ?string $lastOutcome,
        ?int $lastHttpStatus
    ): string {
        $reason = self::resolveReason($channelName, $lastOutcome, $lastHttpStatus);
        return "Gave up after {$attemptCount} attempts: {$reason}. Needs staff attention. Retry once the problem is fixed.";
    }
}
