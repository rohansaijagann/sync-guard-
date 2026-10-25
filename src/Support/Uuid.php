<?php

declare(strict_types=1);

namespace SyncGuard\Support;

/**
 * Class Uuid
 *
 * Lightweight RFC 4122 compliant Version 4 UUID generator and validator.
 * Generates cryptographically secure pseudorandom UUIDs using PHP's random_bytes().
 */
class Uuid
{
    /**
     * Regular expression matching a standard 36-character UUID v4 string.
     * Format: 8-4-4-4-12 hex characters with version 4 in byte 6 and variant 8, 9, a, or b in byte 8.
     */
    public const V4_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    /**
     * Generate a new RFC 4122 Version 4 UUID string.
     *
     * @return string 36-character lowercase UUID v4 (e.g. 550e8400-e29b-41d4-a716-446655440000)
     */
    public static function v4(): string
    {
        // 16 cryptographically secure random bytes = 128 bits
        $bytes = random_bytes(16);

        // Set version to 0100 (v4) at byte 6 (bits 12 through 15 of time_hi_and_version)
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);

        // Set variant to 10xx (RFC 4122) at byte 8 (bits 6 and 7 of clk_seq_hi_res)
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        // Format into 8-4-4-4-12 representation
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * Validate whether a string conforms to the UUID v4 format.
     *
     * @param string|null $uuid String to validate
     * @return bool True if valid UUID v4, false otherwise
     */
    public static function isValid(?string $uuid): bool
    {
        if ($uuid === null || strlen($uuid) !== 36) {
            return false;
        }

        return (bool) preg_match(self::V4_PATTERN, $uuid);
    }
}
