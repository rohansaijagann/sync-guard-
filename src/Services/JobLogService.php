<?php

declare(strict_types=1);

namespace SyncGuard\Services;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use SyncGuard\Repositories\ChannelRepository;
use SyncGuard\Repositories\RoomRepository;
use SyncGuard\Retry\Backoff;
use SyncGuard\Support\JobMessageBuilder;
use SyncGuard\Support\Time;

/**
 * Class JobLogService
 *
 * Provides querying, filtering, pagination, and status aggregation for the Sync Log page.
 *
 * WHY ORDER BY j.id DESC:
 * Paging must remain stable while background workers are actively updating rows.
 * If we ordered by `updated_at`, a job transitioning between states (e.g. pending -> in_progress -> failed)
 * would jump positions across pages, causing users to see duplicate rows or skip unreviewed jobs.
 * Ordering by primary key `j.id DESC` reflects immutable creation order and guarantees stable pagination.
 *
 * WHY COUNTS IGNORE STATUS FILTER:
 * The status count badges in the UI filter bar (e.g. "Dead (2)", "Failed (0)") need to inform the user
 * how many jobs exist across each status for their currently chosen site/room filters.
 * If counts respected the status filter, selecting "Dead" would zero out the numbers for all other statuses,
 * making it impossible for staff to know if other failed or pending jobs exist without clearing the filter first.
 *
 * WHY SINGLE BATCH QUERY FOR LATEST ATTEMPTS:
 * Fetching the latest attempt for each job on a page individually would introduce an N+1 query antipattern
 * (25 jobs = 25 additional database round-trips). Fetching the maximum attempt ID per job in a single batch
 * subquery join (`WHERE job_id IN (...) GROUP BY job_id`) retrieves all attempts for the page in exactly 1 query.
 */
class JobLogService
{
    private ChannelRepository $channelRepo;
    private RoomRepository $roomRepo;

    public function __construct(
        private readonly PDO $pdo,
        ?ChannelRepository $channelRepo = null,
        ?RoomRepository $roomRepo = null
    ) {
        $this->channelRepo = $channelRepo ?? new ChannelRepository($this->pdo);
        $this->roomRepo = $roomRepo ?? new RoomRepository($this->pdo);
    }

    /**
     * Retrieve a filtered, paginated list of sync jobs with options and status counts.
     *
     * @param string $status Filter status: 'all'|'unsynced'|'pending'|'in_progress'|'failed'|'dead'|'success'
     * @param int|null $channelId Optional channel ID filter
     * @param int|null $roomId Optional room ID filter
     * @param int $page 1-indexed page number
     * @param int $perPage Page size: 10, 25, or 50
     * @return array<string, mixed> Structured response data
     */
    public function getJobsData(
        string $status = 'all',
        ?int $channelId = null,
        ?int $roomId = null,
        int $page = 1,
        int $perPage = 25
    ): array {
        // Enforce valid pagination bounds
        $page = max(1, $page);
        $perPage = in_array($perPage, [10, 25, 50], true) ? $perPage : 25;
        $offset = ($page - 1) * $perPage;

        // 1. Fetch channel and room options (tiny static tables)
        $channelRows = $this->channelRepo->getAll();
        $roomRows = $this->roomRepo->getAll();

        $channelsOption = array_map(static fn(array $c): array => [
            'id'     => (int) $c['id'],
            'name'   => (string) $c['name'],
            'active' => (bool) $c['is_active'],
        ], $channelRows);

        $roomsOption = array_map(static fn(array $r): array => [
            'id'   => (int) $r['id'],
            'name' => (string) $r['name'],
        ], $roomRows);

        // 2. Compute status counts (respects channel and room filters, IGNORES status filter)
        $counts = $this->getStatusCounts($channelId, $roomId);

        // 3. Compute total jobs matching current filters (including status)
        $total = $this->getTotalJobsCount($status, $channelId, $roomId);
        $totalPages = max(1, (int) ceil($total / $perPage));

        // 4. Fetch page items (ordered by j.id DESC)
        $items = [];
        if ($total > 0 && $page <= $totalPages) {
            $items = $this->fetchJobItems($status, $channelId, $roomId, $perPage, $offset);
        }

        // Current timestamp in JST
        $nowJst = (new DateTimeImmutable('now', new DateTimeZone(Time::TIMEZONE_JST)))->format('Y-m-d H:i:s \J\S\T');

        return [
            'generated_at_jst' => $nowJst,
            'filters'          => [
                'status'     => $status,
                'channel_id' => $channelId,
                'room_id'    => $roomId,
            ],
            'options'          => [
                'channels' => $channelsOption,
                'rooms'    => $roomsOption,
            ],
            'counts'           => $counts,
            'pagination'       => [
                'page'        => $page,
                'per_page'    => $perPage,
                'total'       => $total,
                'total_pages' => $totalPages,
            ],
            'max_attempts'     => Backoff::MAX_ATTEMPTS,
            'items'            => $items,
        ];
    }

    /**
     * Compute total count for the given filters.
     */
    public function getTotalJobsCount(string $status, ?int $channelId, ?int $roomId): int
    {
        $conditions = [];
        $params = [];

        $this->applyStatusCondition($status, $conditions, $params);
        $this->applyChannelAndRoomConditions($channelId, $roomId, $conditions, $params);

        $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $sql = "SELECT COUNT(*) 
                FROM sync_jobs j
                INNER JOIN inventory_updates u ON u.id = j.update_id
                {$whereClause}";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * Fetch status counts ignoring the status filter.
     *
     * @return array<string, int>
     */
    private function getStatusCounts(?int $channelId, ?int $roomId): array
    {
        $conditions = [];
        $params = [];

        $this->applyChannelAndRoomConditions($channelId, $roomId, $conditions, $params);
        $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $sql = "SELECT j.status, COUNT(*) AS cnt
                FROM sync_jobs j
                INNER JOIN inventory_updates u ON u.id = j.update_id
                {$whereClause}
                GROUP BY j.status";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $counts = [
            'all'         => 0,
            'unsynced'    => 0,
            'pending'     => 0,
            'in_progress' => 0,
            'failed'      => 0,
            'dead'        => 0,
            'success'     => 0,
        ];

        foreach ($rows as $row) {
            $st = (string) $row['status'];
            $cnt = (int) $row['cnt'];
            if (isset($counts[$st])) {
                $counts[$st] = $cnt;
            }
            $counts['all'] += $cnt;
            if ($st !== 'success') {
                $counts['unsynced'] += $cnt;
            }
        }

        return $counts;
    }

    /**
     * Fetch items for a specific page.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchJobItems(
        string $status,
        ?int $channelId,
        ?int $roomId,
        int $limit,
        int $offset
    ): array {
        $conditions = [];
        $params = [];

        $this->applyStatusCondition($status, $conditions, $params);
        $this->applyChannelAndRoomConditions($channelId, $roomId, $conditions, $params);

        $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

        // Uses database clock (TIMESTAMPDIFF against UTC_TIMESTAMP) for retry delay calculation
        $sql = "SELECT 
                    j.id AS job_id,
                    j.update_id,
                    j.channel_id,
                    j.status,
                    j.attempt_count,
                    j.next_attempt_at,
                    j.last_error,
                    u.created_at AS created_at,
                    j.updated_at,
                    TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), j.next_attempt_at) AS next_attempt_in_seconds,
                    u.correlation_id,
                    u.room_id,
                    u.date_from,
                    u.date_to,
                    u.available_units,
                    u.price,
                    c.name AS channel_name,
                    c.is_active AS channel_active,
                    r.name AS room_name
                FROM sync_jobs j
                INNER JOIN inventory_updates u ON u.id = j.update_id
                INNER JOIN channels c ON c.id = j.channel_id
                INNER JOIN rooms r ON r.id = u.room_id
                {$whereClause}
                ORDER BY j.id DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) {
            return [];
        }

        // 5. Batch-fetch the latest attempt for all jobs on this page in ONE query
        $jobIds = array_map(static fn(array $r): int => (int) $r['job_id'], $rows);
        $latestAttempts = $this->fetchLatestAttemptsForJobs($jobIds);

        $items = [];
        foreach ($rows as $row) {
            $jobId = (int) $row['job_id'];
            $st = (string) $row['status'];
            $attemptCount = (int) $row['attempt_count'];
            $channelName = (string) $row['channel_name'];

            $latestAttempt = $latestAttempts[$jobId] ?? null;

            // Retry delay and scheduled time only apply to failed jobs
            $nextAttemptSeconds = ($st === 'failed' && $row['next_attempt_in_seconds'] !== null)
                ? (int) $row['next_attempt_in_seconds']
                : null;

            $nextAttemptJst = ($st === 'failed' && !empty($row['next_attempt_at']))
                ? Time::utcToJst((string) $row['next_attempt_at'], 'Y-m-d H:i:s \J\S\T')
                : null;

            $nextAttemptTimeOnly = ($st === 'failed' && !empty($row['next_attempt_at']))
                ? Time::utcToJst((string) $row['next_attempt_at'], 'H:i')
                : null;

            $lastAttemptData = null;
            if ($latestAttempt !== null) {
                $lastAttemptData = [
                    'outcome'        => (string) $latestAttempt['outcome'],
                    'http_status'    => $latestAttempt['http_status'] !== null ? (int) $latestAttempt['http_status'] : null,
                    'duration_ms'    => (int) $latestAttempt['duration_ms'],
                    'started_at_jst' => Time::utcToJst((string) $latestAttempt['started_at'], 'Y-m-d H:i:s \J\S\T'),
                ];
            }

            // Build plain-language explanation
            $message = JobMessageBuilder::build([
                'status'               => $st,
                'channelName'          => $channelName,
                'attemptCount'         => $attemptCount,
                'maxAttempts'          => Backoff::MAX_ATTEMPTS,
                'lastOutcome'          => $latestAttempt['outcome'] ?? null,
                'lastHttpStatus'       => isset($latestAttempt['http_status']) && $latestAttempt['http_status'] !== null ? (int) $latestAttempt['http_status'] : null,
                'nextAttemptInSeconds' => $nextAttemptSeconds,
                'nextAttemptTimeJst'   => $nextAttemptTimeOnly,
            ]);

            $items[] = [
                'job_id'                  => $jobId,
                'update_id'               => (int) $row['update_id'],
                'correlation_id'          => (string) $row['correlation_id'],
                'channel'                 => [
                    'id'     => (int) $row['channel_id'],
                    'name'   => $channelName,
                    'active' => (bool) $row['channel_active'],
                ],
                'room'                    => [
                    'id'   => (int) $row['room_id'],
                    'name' => (string) $row['room_name'],
                ],
                'date_from'               => (string) $row['date_from'],
                'date_to'                 => (string) $row['date_to'],
                'available_units'         => $row['available_units'] !== null ? (int) $row['available_units'] : null,
                'price'                   => $row['price'] !== null ? (int) $row['price'] : null,
                'status'                  => $st,
                'attempt_count'           => $attemptCount,
                'max_attempts'            => Backoff::MAX_ATTEMPTS,
                'last_error'              => $row['last_error'] !== null ? (string) $row['last_error'] : null,
                'message'                 => $message,
                'created_at_jst'          => Time::utcToJst((string) $row['created_at'], 'Y-m-d H:i:s \J\S\T'),
                'updated_at_jst'          => Time::utcToJst((string) $row['updated_at'], 'Y-m-d H:i:s \J\S\T'),
                'next_attempt_at_jst'     => $nextAttemptJst,
                'next_attempt_in_seconds' => $nextAttemptSeconds,
                'last_attempt'            => $lastAttemptData,
                'can_retry'               => in_array($st, ['failed', 'dead'], true),
            ];
        }

        return $items;
    }

    /**
     * Batch-fetch the single most recent attempt for each provided job ID.
     *
     * @param array<int> $jobIds
     * @return array<int, array<string, mixed>> Keyed by job_id
     */
    private function fetchLatestAttemptsForJobs(array $jobIds): array
    {
        if (empty($jobIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($jobIds), '?'));

        $sql = "SELECT a.id, a.job_id, a.started_at, a.duration_ms, a.http_status, a.outcome
                FROM sync_attempts a
                INNER JOIN (
                    SELECT MAX(id) AS max_id
                    FROM sync_attempts
                    WHERE job_id IN ({$placeholders})
                    GROUP BY job_id
                ) latest ON a.id = latest.max_id";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($jobIds);
        $attempts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $indexed = [];
        foreach ($attempts as $att) {
            $indexed[(int) $att['job_id']] = $att;
        }

        return $indexed;
    }

    /**
     * Apply status filtering logic to the query conditions array.
     */
    private function applyStatusCondition(string $status, array &$conditions, array &$params): void
    {
        if ($status === 'unsynced') {
            $conditions[] = "j.status != 'success'";
        } elseif ($status !== 'all') {
            $conditions[] = 'j.status = :status';
            $params[':status'] = $status;
        }
    }

    /**
     * Apply channel and room filters to conditions array.
     */
    private function applyChannelAndRoomConditions(?int $channelId, ?int $roomId, array &$conditions, array &$params): void
    {
        if ($channelId !== null) {
            $conditions[] = 'j.channel_id = :channel_id';
            $params[':channel_id'] = $channelId;
        }

        if ($roomId !== null) {
            $conditions[] = 'u.room_id = :room_id';
            $params[':room_id'] = $roomId;
        }
    }
}
