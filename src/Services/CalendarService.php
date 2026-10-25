<?php

declare(strict_types=1);

namespace SyncGuard\Services;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use SyncGuard\Support\Time;

/**
 * Class CalendarService
 *
 * Compiles a room-by-room, date-by-date matrix of availability, rates, and operational sync states.
 *
 * WHY AN OLD FAILED JOB STILL MARKS A DATE AT RISK:
 * If an inventory update failed to deliver to Site C yesterday, and a staff member submits a new update today,
 * the old failed job remains in the queue or retry backoff schedule. If staff or an automated worker
 * subsequently retries that old failed job, it could execute out of order and overwrite the newer update
 * on Site C with stale availability. Therefore, ANY un-synced delivery covering a date represents an active
 * overbooking risk until all jobs across all active channels for that date achieve 'success'.
 */
class CalendarService
{
    public const ALLOWED_DAYS = [7, 14, 28];
    public const MAX_UPDATES_LIMIT = 2000;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Compile availability and sync risk matrix.
     *
     * Strictly bounded to at most 4 database queries.
     *
     * @param int $days Number of days (7, 14, 28)
     * @param DateTimeImmutable|null $nowInject Optional clock injection for testing
     * @return array<string, mixed>
     */
    public function getCalendar(int $days = 14, ?DateTimeImmutable $nowInject = null): array
    {
        return $this->getCalendarData($days, $nowInject);
    }

    public function getCalendarData(int $days = 14, ?DateTimeImmutable $nowInject = null): array
    {
        if (!in_array($days, self::ALLOWED_DAYS, true)) {
            throw new \InvalidArgumentException("Days must be 7, 14, or 28.");
        }

        // 1. Determine Start Date (Today in Asia/Tokyo) from Database Clock
        if ($nowInject !== null) {
            $effectiveNowJst = $nowInject->setTimezone(new DateTimeZone(Time::TIMEZONE_JST));
        } else {
            $clockStmt = $this->pdo->query('SELECT UTC_TIMESTAMP() AS now_utc');
            $nowUtcStr = (string) $clockStmt->fetchColumn();
            $nowUtc = new DateTimeImmutable($nowUtcStr, new DateTimeZone('UTC'));
            $effectiveNowJst = $nowUtc->setTimezone(new DateTimeZone(Time::TIMEZONE_JST));
        }

        $startDateStr = $effectiveNowJst->format('Y-m-d');
        $startDate = new DateTimeImmutable($startDateStr, new DateTimeZone(Time::TIMEZONE_JST));
        $endDate = $startDate->modify('+' . ($days - 1) . ' days');
        $endDateStr = $endDate->format('Y-m-d');

        // 2. Build Date Headers
        $dates = [];
        $dateStrings = [];
        for ($i = 0; $i < $days; $i++) {
            $cur = $startDate->modify("+{$i} days");
            $curStr = $cur->format('Y-m-d');
            $weekday = $cur->format('D'); // Mon, Tue, etc.
            $isWeekend = in_array($weekday, ['Sat', 'Sun'], true);

            $dateStrings[] = $curStr;
            $dates[] = [
                'date'       => $curStr,
                'weekday'    => $weekday,
                'is_weekend' => $isWeekend,
            ];
        }

        // 3. Fetch Rooms (Query 2)
        $roomStmt = $this->pdo->query('SELECT id, name, total_units, base_price FROM rooms ORDER BY id ASC');
        $roomRows = $roomStmt->fetchAll(PDO::FETCH_ASSOC);

        // 4. Fetch Updates Overlapping Calendar Window (Query 3)
        // Overlap condition: update.date_to >= window.start AND update.date_from <= window.end
        $updateSql = 'SELECT id, room_id, date_from, date_to, available_units, price
                      FROM inventory_updates
                      WHERE date_to >= :start_date AND date_from <= :end_date
                      ORDER BY id ASC
                      LIMIT ' . (self::MAX_UPDATES_LIMIT + 1);

        $updateStmt = $this->pdo->prepare($updateSql);
        $updateStmt->bindValue(':start_date', $startDateStr, PDO::PARAM_STR);
        $updateStmt->bindValue(':end_date', $endDateStr, PDO::PARAM_STR);
        $updateStmt->execute();
        $rawUpdateRows = $updateStmt->fetchAll(PDO::FETCH_ASSOC);

        $isTruncated = count($rawUpdateRows) > self::MAX_UPDATES_LIMIT;
        $updateRows = array_slice($rawUpdateRows, 0, self::MAX_UPDATES_LIMIT);

        // 5. Fetch Problem Jobs for Overlapping Updates on Active Channels (Query 4)
        $problemJobsByUpdateId = [];
        if (!empty($updateRows)) {
            $updateIds = array_map(static fn(array $u): int => (int) $u['id'], $updateRows);
            $updateIdChunks = array_chunk($updateIds, 1000);

            foreach ($updateIdChunks as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                $jobSql = "SELECT j.update_id, j.channel_id, c.name AS channel_name, j.status
                           FROM sync_jobs j
                           INNER JOIN channels c ON c.id = j.channel_id AND c.is_active = 1
                           WHERE j.update_id IN ({$placeholders})
                             AND j.status != 'success'
                           ORDER BY c.name ASC";

                $jobStmt = $this->pdo->prepare($jobSql);
                $jobStmt->execute($chunk);
                while ($jRow = $jobStmt->fetch(PDO::FETCH_ASSOC)) {
                    $uId = (int) $jRow['update_id'];
                    $problemJobsByUpdateId[$uId][] = [
                        'channel_name' => (string) $jRow['channel_name'],
                        'status'       => (string) $jRow['status'],
                    ];
                }
            }
        }

        // 6. Index Updates by Room ID
        $updatesByRoom = [];
        foreach ($updateRows as $u) {
            $rId = (int) $u['room_id'];
            $updatesByRoom[$rId][] = $u;
        }

        // 7. Assemble Room Matrix Cells
        $roomsData = [];
        foreach ($roomRows as $r) {
            $rId = (int) $r['id'];
            $roomName = (string) $r['name'];
            $defaultUnits = (int) $r['total_units'];
            $defaultPrice = (int) round((float) $r['base_price']);

            $roomUpdates = $updatesByRoom[$rId] ?? [];

            $cells = [];
            foreach ($dateStrings as $dateStr) {
                // Find all updates covering this date in ID ASC order
                $unitsVal = null;
                $priceVal = null;
                $hasUpdateValue = false;
                $updatesCoveringCount = 0;

                // Severity rank: dead (4) > failed (3) > syncing (2) > synced (1)
                $worstRank = 1;
                $channelsByRank = [
                    4 => [], // dead
                    3 => [], // failed
                    2 => [], // syncing (pending or in_progress)
                ];

                foreach ($roomUpdates as $u) {
                    $uFrom = (string) $u['date_from'];
                    $uTo   = (string) $u['date_to'];

                    // Check if update covers this date
                    if ($dateStr >= $uFrom && $dateStr <= $uTo) {
                        $updatesCoveringCount++;

                        if ($u['available_units'] !== null) {
                            $unitsVal = (int) $u['available_units'];
                            $hasUpdateValue = true;
                        }
                        if ($u['price'] !== null) {
                            $priceVal = (int) round((float) $u['price']);
                            $hasUpdateValue = true;
                        }

                        // Inspect problem jobs of this covering update
                        $uId = (int) $u['id'];
                        if (isset($problemJobsByUpdateId[$uId])) {
                            foreach ($problemJobsByUpdateId[$uId] as $prob) {
                                $cName = (string) $prob['channel_name'];
                                $jStatus = (string) $prob['status'];

                                $rank = match ($jStatus) {
                                    'dead'                     => 4,
                                    'failed'                   => 3,
                                    'pending', 'in_progress'   => 2,
                                    default                    => 1,
                                };

                                if ($rank > 1) {
                                    $channelsByRank[$rank][$cName] = true;
                                    if ($rank > $worstRank) {
                                        $worstRank = $rank;
                                    }
                                }
                            }
                        }
                    }
                }

                $state = match ($worstRank) {
                    4       => 'dead',
                    3       => 'failed',
                    2       => 'syncing',
                    default => 'synced',
                };

                $channels = [];
                if ($worstRank > 1) {
                    $channels = array_keys($channelsByRank[$worstRank]);
                    sort($channels);
                }

                $cells[] = [
                    'date'             => $dateStr,
                    'units'            => $unitsVal ?? $defaultUnits,
                    'price'            => $priceVal ?? $defaultPrice,
                    'source'           => $hasUpdateValue ? 'update' : 'default',
                    'state'            => $state,
                    'channels'         => $channels,
                    'updates_covering' => $updatesCoveringCount,
                ];
            }

            $roomsData[] = [
                'room_id'     => $rId,
                'room_name'   => $roomName,
                'total_units' => $defaultUnits,
                'base_price'  => $defaultPrice,
                'cells'       => $cells,
            ];
        }

        return [
            'start_date' => $startDateStr,
            'days'       => $days,
            'dates'      => $dates,
            'rooms'      => $roomsData,
            'truncated'  => $isTruncated,
        ];
    }
}
