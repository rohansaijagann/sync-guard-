<?php

declare(strict_types=1);

namespace SyncGuard\Support;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Class Time
 *
 * Handles time conversions between MySQL UTC storage and Japanese Standard Time (JST) presentation.
 *
 * WHY UTC IN DATABASE, JST IN UI:
 * Hotel distribution systems exchange data with global booking channels (OTAs) operating
 * across multiple partner time zones.
 * Storing timestamps strictly in UTC (+00:00) eliminates daylight-saving discrepancies and
 * provides an unambiguous single source of truth for queue scheduling and retry intervals.
 * Converting to JST (+09:00) occurs exclusively at the presentation layer so Japanese hotel
 * staff view familiar local operational times.
 */
class Time
{
    public const TIMEZONE_UTC = 'UTC';
    public const TIMEZONE_JST = 'Asia/Tokyo';

    /**
     * Convert a UTC datetime string (from MySQL) to a localized JST display string.
     *
     * @param string|null $utcDateTimeString e.g. "2026-10-24 08:30:00"
     * @param string $format Target date format, defaults to "Y-m-d H:i JST"
     * @return string Formatted JST datetime string, or "-" if null/empty
     */
    public static function utcToJst(?string $utcDateTimeString, string $format = 'Y-m-d H:i \J\S\T'): string
    {
        if ($utcDateTimeString === null || trim($utcDateTimeString) === '') {
            return '-';
        }

        try {
            $utc = new DateTimeZone(self::TIMEZONE_UTC);
            $jst = new DateTimeZone(self::TIMEZONE_JST);

            $date = new DateTimeImmutable($utcDateTimeString, $utc);
            $dateJst = $date->setTimezone($jst);

            return $dateJst->format($format);
        } catch (Throwable) {
            return $utcDateTimeString . ' (UTC)';
        }
    }

    /**
     * Return today's calendar date in Asia/Tokyo (Y-m-d).
     *
     * @return string e.g. "2026-10-24"
     */
    public static function todayInTokyo(): string
    {
        $jst = new DateTimeZone(self::TIMEZONE_JST);
        return (new DateTimeImmutable('now', $jst))->format('Y-m-d');
    }
}
