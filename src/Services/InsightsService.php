<?php

declare(strict_types=1);

namespace SyncGuard\Services;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use SyncGuard\Support\Time;

/**
 * Class InsightsService
 *
 * Compiles telemetry metrics for the operational insights dashboard:
 * 1. Time to Consistency (duration from inventory update creation until all active channels succeed).
 * 2. 24-Hour Delivery Volume & Error Trends (hourly buckets with PHP gap-filling).
 * 3. Recent Delivery Activity Feed (15 newest delivery attempts).
 *
 * DEFINITION OF TIME TO CONSISTENCY:
 * Time to consistency measures the duration from `inventory_updates.created_at` until the LAST of its
 * jobs on ACTIVE channels was delivered (the `started_at` timestamp of that job's successful attempt).
 * An update qualifies only when all of its jobs on active channels have achieved 'success'.
 * It deliberately includes retried and slow jobs because it measures "how long were the sites out of sync".
 *
 * WHY P95 (95TH PERCENTILE) NEXT TO THE AVERAGE:
 * In distributed queue processing, an arithmetic average hides critical tail latency caused by transient
 * network delays, backoff retry waits, and temporary channel degradation. While 90% of jobs might finish
 * in 3 seconds, a few retried jobs might take 4 minutes. Showing p95 exposes the true customer-facing
 * experience for edge cases without being skewed by a single massive outlier.
 *
 * WHY RECENT FEED ITEMS CARRY NO BOOKING-SITE TEXT:
 * External booking channels (OTAs) return arbitrary, unpredictable, and sometimes technical or unescaped
 * error text (e.g. raw XML fragments, stack traces, HTML error pages). Exposing third-party raw strings
 * directly in operational feeds risks confusing staff, breaking design layouts, and introducing XSS vectors.
 * The feed provides deterministic, sanitized labels ("Delivered", "Timed out", "Server error", "Refused")
 * owned entirely by our system.
 */
class InsightsService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly int $windowHours = 24
    ) {
    }

    /**
     * Compile complete insights data payload.
     *
     * Bounded strictly to at most 4 database queries.
     *
     * @param DateTimeImmutable|null $nowInject Optional clock injection for testing
     * @return array<string, mixed>
     */
    public function getInsightsData(?DateTimeImmutable $nowInject = null): array
    {
        // 1. Fetch current database time in UTC
        $clockStmt = $this->pdo->query('SELECT UTC_TIMESTAMP() AS now_utc');
        $dbNowUtcStr = (string) $clockStmt->fetchColumn();
        $dbNowUtc = new DateTimeImmutable($dbNowUtcStr, new DateTimeZone('UTC'));
        $effectiveNow = $nowInject ?? $dbNowUtc;

        $generatedAtJst = Time::utcToJst($effectiveNow->format('Y-m-d H:i:s'), 'Y-m-d H:i:s \J\S\T');

        // 2. Query Consistency & Unsynced Updates (Query 2)
        $consistencyData = $this->calculateConsistency($this->windowHours);

        // 3. Query Hourly Delivery Statistics (Query 3)
        $hourlyData = $this->calculateHourly($effectiveNow);

        // 4. Query Recent Attempt Feed (Query 4)
        $recentData = $this->fetchRecentAttempts();

        return [
            'generated_at_jst' => $generatedAtJst,
            'consistency'      => $consistencyData,
            'hourly'           => $hourlyData,
            'recent'           => $recentData,
        ];
    }

    public function getConsistencyMetrics(?int $windowHours = null): array
    {
        return $this->calculateConsistency($windowHours ?? $this->windowHours);
    }

    public function getHourlyDeliveries(?DateTimeImmutable $nowInject = null): array
    {
        if ($nowInject === null) {
            $clockStmt = $this->pdo->query('SELECT UTC_TIMESTAMP() AS now_utc');
            $dbNowUtcStr = (string) $clockStmt->fetchColumn();
            $nowInject = new DateTimeImmutable($dbNowUtcStr, new DateTimeZone('UTC'));
        }
        return $this->calculateHourly($nowInject);
    }

    public function getRecentActivity(int $limit = 15): array
    {
        return $this->fetchRecentAttempts($limit);
    }

    /**
     * Compute Time to Consistency statistics and unsynced update counts.
     *
     * @param int $windowHours
     * @return array{
     *     window_hours: int,
     *     sample_size: int,
     *     avg_seconds: int|null,
     *     median_seconds: int|null,
     *     p95_seconds: int|null,
     *     max_seconds: int|null,
     *     enough_for_p95: bool,
     *     unsynced_updates: int,
     *     oldest_unsynced_seconds: int|null
     * }
     */
    public function calculateConsistency(int $windowHours): array
    {
        $safeHours = max(1, $windowHours);

        // Fetches up to 500 newest updates in the time window with active channels
        $sql = "SELECT 
                    u.id AS update_id,
                    u.created_at,
                    COUNT(j.id) AS total_active_jobs,
                    SUM(CASE WHEN j.status = 'success' THEN 1 ELSE 0 END) AS success_active_jobs,
                    MAX(sa.success_started_at) AS last_delivery_at,
                    TIMESTAMPDIFF(SECOND, u.created_at, MAX(sa.success_started_at)) AS duration_seconds,
                    TIMESTAMPDIFF(SECOND, u.created_at, UTC_TIMESTAMP()) AS age_seconds
                FROM inventory_updates u
                INNER JOIN sync_jobs j ON j.update_id = u.id
                INNER JOIN channels c ON c.id = j.channel_id AND c.is_active = 1
                LEFT JOIN (
                    SELECT job_id, MAX(started_at) AS success_started_at
                    FROM sync_attempts
                    WHERE outcome = 'success'
                    GROUP BY job_id
                ) sa ON sa.job_id = j.id
                WHERE u.created_at >= UTC_TIMESTAMP() - INTERVAL :hours HOUR
                GROUP BY u.id, u.created_at
                ORDER BY u.id DESC
                LIMIT 500";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':hours', $safeHours, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $durations = [];
        $unsyncedCount = 0;
        $oldestUnsyncedAge = null;

        foreach ($rows as $row) {
            $totalActive = (int) $row['total_active_jobs'];
            $successActive = (int) $row['success_active_jobs'];
            $ageSeconds = max(0, (int) ($row['age_seconds'] ?? 0));

            // Must have at least one job on an active channel
            if ($totalActive === 0) {
                continue;
            }

            if ($successActive === $totalActive) {
                // Fully synced update: compute time to consistency
                // If duration_seconds is null (e.g. edge case where attempt not found), default to 0
                $duration = max(0, (int) ($row['duration_seconds'] ?? 0));
                $durations[] = $duration;
            } else {
                // Unsynced update
                $unsyncedCount++;
                if ($oldestUnsyncedAge === null || $ageSeconds > $oldestUnsyncedAge) {
                    $oldestUnsyncedAge = $ageSeconds;
                }
            }
        }

        $sampleSize = count($durations);

        if ($sampleSize === 0) {
            return [
                'window_hours'            => $safeHours,
                'sample_size'             => 0,
                'avg_seconds'             => null,
                'median_seconds'          => null,
                'p95_seconds'             => null,
                'max_seconds'             => null,
                'enough_for_p95'          => false,
                'unsynced_updates'        => $unsyncedCount,
                'oldest_unsynced_seconds' => $oldestUnsyncedAge,
            ];
        }

        sort($durations, SORT_NUMERIC);

        // Average
        $avg = (int) round(array_sum($durations) / $sampleSize);

        // Median (middle element or mean of two middle elements rounded)
        $mid = intdiv($sampleSize, 2);
        if ($sampleSize % 2 === 1) {
            $median = $durations[$mid];
        } else {
            $median = (int) round(($durations[$mid - 1] + $durations[$mid]) / 2);
        }

        $max = max($durations);

        // p95 by nearest-rank method: index = ceil(0.95 * n) - 1
        $enoughForP95 = ($sampleSize >= 5);
        $p95 = null;
        if ($enoughForP95) {
            $p95Index = (int) (ceil(0.95 * $sampleSize) - 1);
            $p95Index = max(0, min($sampleSize - 1, $p95Index));
            $p95 = $durations[$p95Index];
        }

        return [
            'window_hours'            => $safeHours,
            'sample_size'             => $sampleSize,
            'avg_seconds'             => $avg,
            'median_seconds'          => $median,
            'p95_seconds'             => $p95,
            'max_seconds'             => $max,
            'enough_for_p95'          => $enoughForP95,
            'unsynced_updates'        => $unsyncedCount,
            'oldest_unsynced_seconds' => $oldestUnsyncedAge,
        ];
    }

    /**
     * Compute 24 hourly buckets of success and failed attempts ending with current hour.
     *
     * Gaps are filled in PHP with zeros.
     *
     * @param DateTimeImmutable $nowClock
     * @return array<int, array{hour_jst: string, success: int, failed: int}>
     */
    public function calculateHourly(DateTimeImmutable $nowClock): array
    {
        // 1. Build list of the last 24 whole hours in JST (from 23 hours ago up to current hour)
        $nowJst = $nowClock->setTimezone(new DateTimeZone(Time::TIMEZONE_JST));
        $currentHourJst = $nowJst->setTime((int) $nowJst->format('H'), 0, 0);

        $buckets = [];
        for ($i = 23; $i >= 0; $i--) {
            $bucketTime = $currentHourJst->modify("-{$i} hours");
            $key = $bucketTime->format('Y-m-d H:00');
            $buckets[$key] = [
                'hour_jst' => $bucketTime->format('H:00'),
                'success'  => 0,
                'failed'   => 0,
            ];
        }

        // 2. Query attempts grouped by hour in JST
        $sql = "SELECT 
                    DATE_FORMAT(CONVERT_TZ(a.started_at, '+00:00', '+09:00'), '%Y-%m-%d %H:00') AS hour_key,
                    SUM(CASE WHEN a.outcome = 'success' THEN 1 ELSE 0 END) AS success_cnt,
                    SUM(CASE WHEN a.outcome != 'success' THEN 1 ELSE 0 END) AS failed_cnt
                FROM sync_attempts a
                WHERE a.started_at >= UTC_TIMESTAMP() - INTERVAL 24 HOUR
                GROUP BY hour_key";

        $stmt = $this->pdo->query($sql);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $key = (string) $row['hour_key'];
            if (isset($buckets[$key])) {
                $buckets[$key]['success'] = (int) $row['success_cnt'];
                $buckets[$key]['failed']  = (int) $row['failed_cnt'];
            }
        }

        return array_values($buckets);
    }

    /**
     * Fetch 15 most recent delivery attempts with sanitized plain-language outcome labels.
     *
     * @return array<int, array{
     *     attempt_id: int,
     *     job_id: int,
     *     channel_id: int,
     *     when_jst: string,
     *     seconds_ago: int,
     *     channel: string,
     *     room_name: string,
     *     outcome: string,
     *     label: string,
     *     duration_ms: int
     * }>
     */
    public function fetchRecentAttempts(int $limit = 15): array
    {
        $limit = max(1, min(100, $limit));
        $sql = "SELECT 
                    a.id AS attempt_id,
                    a.job_id,
                    j.channel_id,
                    c.name AS channel,
                    r.name AS room_name,
                    a.started_at,
                    TIMESTAMPDIFF(SECOND, a.started_at, UTC_TIMESTAMP()) AS seconds_ago,
                    a.outcome,
                    a.duration_ms
                FROM sync_attempts a
                INNER JOIN sync_jobs j ON j.id = a.job_id
                INNER JOIN channels c ON c.id = j.channel_id
                INNER JOIN inventory_updates u ON u.id = j.update_id
                INNER JOIN rooms r ON r.id = u.room_id
                ORDER BY a.id DESC
                LIMIT {$limit}";

        $stmt = $this->pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $items = [];
        foreach ($rows as $row) {
            $outcome = (string) $row['outcome'];
            $label = match ($outcome) {
                'success'    => 'Delivered',
                'timeout'    => 'Timed out',
                'http_error' => 'Server error',
                'rejected'   => 'Refused',
                default      => ucfirst($outcome),
            };

            $items[] = [
                'attempt_id'  => (int) $row['attempt_id'],
                'job_id'      => (int) $row['job_id'],
                'channel_id'  => (int) $row['channel_id'],
                'when_jst'    => Time::utcToJst((string) $row['started_at'], 'H:i:s \J\S\T'),
                'seconds_ago' => max(0, (int) ($row['seconds_ago'] ?? 0)),
                'channel'     => (string) $row['channel'],
                'room_name'   => (string) $row['room_name'],
                'outcome'     => $outcome,
                'label'       => $label,
                'duration_ms' => (int) $row['duration_ms'],
            ];
        }

        return $items;
    }
}
