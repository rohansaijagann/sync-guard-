<?php

declare(strict_types=1);

namespace SyncGuard\Services;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use SyncGuard\Support\Heartbeat;
use SyncGuard\Support\Time;

/**
 * StatusService
 *
 * Compiles real-time telemetry, channel reliability metrics, worker heartbeat,
 * and at-risk room inventory for the operational dashboard and API.
 *
 * WHY AT-RISK INVENTORY IS CALCULATED ON REQUEST AND NEVER STORED:
 * In hotel room management, stale or desynchronized inventory across channels leads directly to
 * double-bookings (overbooking). If an "is_at_risk" flag were stored on room rows, background job retries,
 * worker crashes, manual retries, or partial deliveries could easily leave the stored flag out of sync
 * with reality. Calculating at-risk status dynamically on every request guarantees that staff always
 * see the ground truth of what is currently out of sync across external partners.
 *
 * QUERY EFFICIENCY & CONCURRENCY:
 * All metrics across all channels and rooms are compiled using at most 5 targeted queries (strictly <= 6),
 * utilizing MySQL window functions, GROUP BY aggregation, and database clock (UTC_TIMESTAMP) timestamp arithmetic.
 */
class StatusService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Build comprehensive system status snapshot.
     *
     * @return array<string, mixed>
     */
    public function getStatus(): array
    {
        return $this->getStatusData();
    }

    public function getStatusData(): array
    {
        $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $generatedAtUtc = $nowUtc->format('Y-m-d\TH:i:s\Z');
        $generatedAtJst = Time::utcToJst($nowUtc->format('Y-m-d H:i:s'), 'Y-m-d H:i:s \J\S\T');

        // 1. Worker Heartbeat
        $workerInfo = Heartbeat::evaluate();
        $workerData = [
            'state'                   => $workerInfo['state'],
            'seconds_since_last_pass' => $workerInfo['seconds_since_last_pass'],
        ];

        // 2. Channels and their health metrics (Query 1)
        $channelStmt = $this->pdo->query(
            'SELECT 
                c.id, c.name, c.is_active,
                h.success_rate, h.avg_response_ms, h.consecutive_failures,
                h.last_success_at, h.last_failure_at,
                TIMESTAMPDIFF(SECOND, h.last_success_at, UTC_TIMESTAMP()) AS last_success_seconds_ago,
                TIMESTAMPDIFF(SECOND, h.last_failure_at, UTC_TIMESTAMP()) AS last_failure_seconds_ago
             FROM channels c
             LEFT JOIN channel_health h ON c.id = h.channel_id
             ORDER BY c.id ASC'
        );
        $channelRows = $channelStmt->fetchAll(PDO::FETCH_ASSOC);

        // 3. Aggregate queue job counts per channel and status (Query 2)
        $jobStmt = $this->pdo->query(
            'SELECT channel_id, status, COUNT(*) AS cnt
             FROM sync_jobs
             GROUP BY channel_id, status'
        );
        $jobCountsByChannel = [];
        while ($row = $jobStmt->fetch(PDO::FETCH_ASSOC)) {
            $cid = (int) $row['channel_id'];
            $st  = (string) $row['status'];
            $cnt = (int) $row['cnt'];
            if (!isset($jobCountsByChannel[$cid])) {
                $jobCountsByChannel[$cid] = [
                    'pending'     => 0,
                    'in_progress' => 0,
                    'failed'      => 0,
                    'dead'        => 0,
                    'success'     => 0,
                ];
            }
            $jobCountsByChannel[$cid][$st] = $cnt;
        }

        // 3b. Recent success streaks per channel (Query 3: Window function over last RECOVERY_STREAK attempts)
        // WHY A STREAK AND NOT THE SUCCESS RATE ALONE:
        // The rolling 50-attempt rate looks backward over a broad window; old failures can keep
        // a repaired site degraded for dozens of calls. The streak demonstrates that the partner works NOW.
        $streakSql = sprintf(
            'SELECT channel_id, outcome, row_num
             FROM (
                 SELECT 
                     j.channel_id,
                     a.outcome,
                     ROW_NUMBER() OVER (PARTITION BY j.channel_id ORDER BY a.id DESC) AS row_num
                 FROM sync_attempts a
                 INNER JOIN sync_jobs j ON j.id = a.job_id
             ) sub
             WHERE row_num <= %d
             ORDER BY channel_id ASC, row_num ASC',
            HealthService::RECOVERY_STREAK
        );
        $streakStmt = $this->pdo->query($streakSql);
        $attemptsByChannel = [];
        while ($sRow = $streakStmt->fetch(PDO::FETCH_ASSOC)) {
            $cid = (int) $sRow['channel_id'];
            $attemptsByChannel[$cid][] = (string) $sRow['outcome'];
        }

        $streaksByChannel = [];
        foreach ($channelRows as $cRow) {
            $cid = (int) $cRow['id'];
            $streak = 0;
            if (isset($attemptsByChannel[$cid])) {
                foreach ($attemptsByChannel[$cid] as $outcome) {
                    if ($outcome === 'success') {
                        $streak++;
                    } else {
                        break;
                    }
                }
            }
            $streaksByChannel[$cid] = min(HealthService::RECOVERY_STREAK, $streak);
        }

        // 4. Compile channel objects and overall aggregates
        $channelsData = [];
        $overallJobs = [
            'pending'     => 0,
            'in_progress' => 0,
            'failed'      => 0,
            'dead'        => 0,
            'success'     => 0,
        ];
        $activeChannelsWithData = 0;
        $activeSuccessRatesSum = 0.0;
        $activeAvgResponseSum = 0;
        $worstActiveStatusRank = 0; // 0=none, 1=no_data, 2=healthy, 3=recovering, 4=degraded, 5=down
        $attentionNeeded = 0;

        foreach ($channelRows as $cRow) {
            $cid = (int) $cRow['id'];
            $isActive = ((int) $cRow['is_active']) === 1;
            $hasData = ($cRow['last_success_at'] !== null || $cRow['last_failure_at'] !== null);
            $consecFailures = (int) ($cRow['consecutive_failures'] ?? 0);
            $successRate = (float) ($cRow['success_rate'] ?? 100.00);
            $avgResponseMs = (int) ($cRow['avg_response_ms'] ?? 0);
            $recentStreak = $streaksByChannel[$cid] ?? 0;

            if ($isActive) {
                $statusLabel = HealthService::labelFor($hasData, $consecFailures, $successRate, $recentStreak);
            } else {
                $statusLabel = 'paused';
            }

            $jobs = $jobCountsByChannel[$cid] ?? [
                'pending'     => 0,
                'in_progress' => 0,
                'failed'      => 0,
                'dead'        => 0,
                'success'     => 0,
            ];

            if ($isActive) {
                $overallJobs['pending']     += $jobs['pending'];
                $overallJobs['in_progress'] += $jobs['in_progress'];
                $overallJobs['failed']      += $jobs['failed'];
                $overallJobs['dead']        += $jobs['dead'];
                $overallJobs['success']     += $jobs['success'];
                $attentionNeeded            += $jobs['dead'];

                if ($hasData) {
                    $activeChannelsWithData++;
                    $activeSuccessRatesSum += $successRate;
                    $activeAvgResponseSum += $avgResponseMs;
                }

                $rank = match ($statusLabel) {
                    'down'       => 5,
                    'degraded'   => 4,
                    'recovering' => 3,
                    'healthy'    => 2,
                    default      => 1, // 'no_data'
                };
                if ($rank > $worstActiveStatusRank) {
                    $worstActiveStatusRank = $rank;
                }
            }

            $channelsData[] = [
                'id'                       => $cid,
                'name'                     => (string) $cRow['name'],
                'active'                   => $isActive,
                'status'                   => $statusLabel,
                'recent_streak'            => $recentStreak,
                'success_rate'             => $successRate,
                'avg_response_ms'          => $avgResponseMs,
                'consecutive_failures'     => $consecFailures,
                'jobs'                     => $jobs,
                'last_success_at_jst'      => $cRow['last_success_at'] !== null
                    ? Time::utcToJst((string) $cRow['last_success_at'], 'Y-m-d H:i:s \J\S\T')
                    : null,
                'last_success_seconds_ago' => $cRow['last_success_seconds_ago'] !== null
                    ? max(0, (int) $cRow['last_success_seconds_ago'])
                    : null,
                'last_failure_at_jst'      => $cRow['last_failure_at'] !== null
                    ? Time::utcToJst((string) $cRow['last_failure_at'], 'Y-m-d H:i:s \J\S\T')
                    : null,
                'last_failure_seconds_ago' => $cRow['last_failure_seconds_ago'] !== null
                    ? max(0, (int) $cRow['last_failure_seconds_ago'])
                    : null,
            ];
        }

        // Overall status computation: worst active status (down > degraded > recovering > healthy > no_data)
        $overallStatus = match ($worstActiveStatusRank) {
            5       => 'down',
            4       => 'degraded',
            3       => 'recovering',
            2       => 'healthy',
            default => 'no_data',
        };

        $overallSuccessRate = $activeChannelsWithData > 0
            ? round($activeSuccessRatesSum / $activeChannelsWithData, 2)
            : 100.00;

        $overallAvgResponse = $activeChannelsWithData > 0
            ? (int) round($activeAvgResponseSum / $activeChannelsWithData)
            : 0;

        // 5. At-Risk Rooms (Query 3 & Query 4)
        $atRiskData = $this->calculateAtRiskRooms();

        $overallData = [
            'status'           => $overallStatus,
            'success_rate'     => $overallSuccessRate,
            'avg_response_ms'  => $overallAvgResponse,
            'jobs'             => $overallJobs,
            'at_risk_rooms'    => $atRiskData['total_rooms'],
            'attention_needed' => $attentionNeeded,
        ];

        return [
            'generated_at_utc' => $generatedAtUtc,
            'generated_at_jst' => $generatedAtJst,
            'worker'           => $workerData,
            'overall'          => $overallData,
            'channels'         => $channelsData,
            'at_risk'          => $atRiskData,
        ];
    }

    /**
     * Calculate at-risk room inventory across active channels.
     * Bounded to at most 2 database queries.
     *
     * @return array{total_rooms: int, shown: int, rooms: array<int, array<string, mixed>>}
     */
    private function calculateAtRiskRooms(): array
    {
        // Query 3: Find all rooms with unsynced updates on active channels
        $roomStmt = $this->pdo->query(
            'SELECT 
                r.id AS room_id,
                r.name AS room_name,
                COUNT(DISTINCT u.id) AS unsynced_updates,
                MIN(u.date_from) AS date_from,
                MAX(u.date_to) AS date_to,
                MIN(u.created_at) AS oldest_unsynced_created_at,
                MAX(CASE 
                    WHEN j.status = "dead" THEN 3
                    WHEN j.status = "failed" THEN 2
                    ELSE 1 
                END) AS severity_rank
             FROM rooms r
             JOIN inventory_updates u ON u.room_id = r.id
             JOIN sync_jobs j ON j.update_id = u.id
             JOIN channels c ON c.id = j.channel_id
             WHERE c.is_active = 1 
               AND j.status != "success"
             GROUP BY r.id, r.name
             ORDER BY severity_rank DESC, oldest_unsynced_created_at ASC'
        );

        $allRooms = $roomStmt->fetchAll(PDO::FETCH_ASSOC);
        $totalRooms = count($allRooms);

        if ($totalRooms === 0) {
            return [
                'total_rooms' => 0,
                'shown'       => 0,
                'rooms'       => [],
            ];
        }

        // Limit to top 20 rooms for display
        $topRooms = array_slice($allRooms, 0, 20);
        $topRoomIds = array_map(fn($r) => (int) $r['room_id'], $topRooms);

        // Query 4: Fetch channel problems for the top rooms
        $inClause = implode(',', $topRoomIds);
        $probStmt = $this->pdo->query(
            "SELECT 
                u.room_id,
                c.id AS channel_id,
                c.name AS channel_name,
                j.id AS job_id,
                j.status,
                j.attempt_count,
                j.last_error,
                TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), j.next_attempt_at) AS next_attempt_in_seconds,
                j.updated_at
             FROM sync_jobs j
             JOIN inventory_updates u ON j.update_id = u.id
             JOIN channels c ON j.channel_id = c.id
             WHERE c.is_active = 1
               AND j.status != 'success'
               AND u.room_id IN ({$inClause})
             ORDER BY u.room_id ASC, j.id DESC"
        );

        $problemsByRoomChannel = [];
        while ($pRow = $probStmt->fetch(PDO::FETCH_ASSOC)) {
            $rid = (int) $pRow['room_id'];
            $cName = (string) $pRow['channel_name'];
            $status = (string) $pRow['status'];
            $rank = match ($status) {
                'dead'        => 4,
                'failed'      => 3,
                'in_progress' => 2,
                default       => 1, // 'pending'
            };

            if (!isset($problemsByRoomChannel[$rid][$cName])) {
                $problemsByRoomChannel[$rid][$cName] = [
                    'channel'                 => $cName,
                    'status'                  => $status,
                    'status_rank'             => $rank,
                    'jobs'                    => 1,
                    'attempt_count'           => (int) $pRow['attempt_count'],
                    'last_error'              => $pRow['last_error'],
                    'next_attempt_in_seconds' => ($status === 'failed' && $pRow['next_attempt_in_seconds'] !== null)
                        ? max(0, (int) $pRow['next_attempt_in_seconds'])
                        : null,
                ];
            } else {
                $problemsByRoomChannel[$rid][$cName]['jobs']++;
                // Keep the worst status among jobs for this channel
                if ($rank > $problemsByRoomChannel[$rid][$cName]['status_rank']) {
                    $problemsByRoomChannel[$rid][$cName]['status'] = $status;
                    $problemsByRoomChannel[$rid][$cName]['status_rank'] = $rank;
                    $problemsByRoomChannel[$rid][$cName]['attempt_count'] = (int) $pRow['attempt_count'];
                    $problemsByRoomChannel[$rid][$cName]['last_error'] = $pRow['last_error'];
                    $problemsByRoomChannel[$rid][$cName]['next_attempt_in_seconds'] = ($status === 'failed' && $pRow['next_attempt_in_seconds'] !== null)
                        ? max(0, (int) $pRow['next_attempt_in_seconds'])
                        : null;
                }
            }
        }

        // Build room items
        $roomItems = [];
        foreach ($topRooms as $rRow) {
            $rid = (int) $rRow['room_id'];
            $severity = match ((int) $rRow['severity_rank']) {
                3       => 'critical',
                2       => 'warning',
                default => 'info',
            };

            $channelProblems = [];
            if (isset($problemsByRoomChannel[$rid])) {
                foreach ($problemsByRoomChannel[$rid] as $prob) {
                    unset($prob['status_rank']);
                    $channelProblems[] = $prob;
                }
            }

            $roomItems[] = [
                'room_id'                   => $rid,
                'room_name'                 => (string) $rRow['room_name'],
                'severity'                  => $severity,
                'unsynced_updates'          => (int) $rRow['unsynced_updates'],
                'date_from'                 => (string) $rRow['date_from'],
                'date_to'                   => (string) $rRow['date_to'],
                'oldest_unsynced_since_jst' => Time::utcToJst((string) $rRow['oldest_unsynced_created_at'], 'Y-m-d H:i:s \J\S\T'),
                'problems'                  => $channelProblems,
            ];
        }

        return [
            'total_rooms' => $totalRooms,
            'shown'       => count($roomItems),
            'rooms'       => $roomItems,
        ];
    }
}
