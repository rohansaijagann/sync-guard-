<?php

declare(strict_types=1);

namespace SyncGuard\Support;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Heartbeat
 *
 * Emits and inspects health signals from the standalone worker daemon.
 *
 * WHY A WORKER HEARTBEAT IS ESSENTIAL:
 * The queue worker runs as an independent CLI daemon or systemd service, completely separated
 * from the Apache web server and PHP-FPM web processes.
 * Without a heartbeat, if the worker daemon crashes, is killed, or hangs, the web dashboard
 * would simply observe queue jobs piling up indefinitely in 'pending' or 'failed' states.
 * Front-desk and IT staff would have no way to distinguish between "external booking sites are slow"
 * and "the background worker process is completely dead".
 *
 * The worker writes a tiny JSON payload at the start and end of every pass.
 * If the last recorded pass is older than max(3 * interval_seconds, 15s), the dashboard flags
 * the worker state as 'stale' (stopped), alerting operations immediately.
 */
class Heartbeat
{
    public const DEFAULT_FILE = 'storage/worker_heartbeat.json';

    /**
     * Atomically write worker heartbeat state to disk.
     * Uses a temporary file and atomic rename to prevent web processes from reading partial writes.
     * Guaranteed to never throw or interrupt the worker pass if filesystem I/O fails.
     *
     * @param string $mode 'loop' (continuous daemon) or 'once' (single pass)
     * @param int $intervalSeconds Loop sleep interval in seconds
     * @param string|null $filePath Optional custom file path (used in tests)
     * @param DateTimeImmutable|null $now Optional mock time
     */
    public static function record(
        string $mode,
        int $intervalSeconds,
        ?string $filePath = null,
        ?DateTimeImmutable $now = null
    ): void {
        try {
            $path = $filePath ?? (dirname(__DIR__, 2) . '/' . self::DEFAULT_FILE);
            $dir = dirname($path);
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }

            $currentTime = $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $payload = [
                'last_pass_utc'    => $currentTime->format('Y-m-d\TH:i:s\Z'),
                'pid'              => getmypid() ?: 0,
                'interval_seconds' => $intervalSeconds,
                'mode'             => $mode,
            ];

            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                return;
            }

            $tmpFile = $path . '.tmp.' . bin2hex(random_bytes(4));
            if (file_put_contents($tmpFile, $json, LOCK_EX) !== false) {
                @chmod($tmpFile, 0644);
                if (!@rename($tmpFile, $path)) {
                    // Fallback direct write if atomic rename fails on certain Windows/NFS mounts
                    file_put_contents($path, $json, LOCK_EX);
                    @chmod($path, 0644);
                    @unlink($tmpFile);
                }
            }
        } catch (Throwable $e) {
            // Heartbeat failure must NEVER crash or abort the worker pass
            error_log(sprintf('[Heartbeat] Failed to write worker heartbeat: %s', $e->getMessage()));
        }
    }

    /**
     * Inspect worker heartbeat file and evaluate operational state.
     *
     * State evaluation:
     * - 'online'  : Last pass was recorded within max(3 * interval, 15) seconds.
     * - 'stale'   : Heartbeat exists, but timestamp is older than the freshness threshold (worker stopped).
     * - 'unknown' : Heartbeat file is missing, unreadable, or contains corrupted JSON.
     *
     * @param string|null $filePath Custom path (for testing)
     * @param DateTimeImmutable|null $now Current time
     * @return array{
     *     state: string,
     *     seconds_since_last_pass: int|null,
     *     pid: int|null,
     *     interval_seconds: int|null,
     *     mode: string|null,
     *     last_pass_utc: string|null
     * }
     */
    public static function evaluate(?string $filePath = null, ?DateTimeImmutable $now = null): array
    {
        $path = $filePath ?? (dirname(__DIR__, 2) . '/' . self::DEFAULT_FILE);

        if (!file_exists($path) || !is_readable($path)) {
            return [
                'state'                   => 'unknown',
                'seconds_since_last_pass' => null,
                'pid'                     => null,
                'interval_seconds'        => null,
                'mode'                    => null,
                'last_pass_utc'           => null,
            ];
        }

        $raw = @file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return [
                'state'                   => 'unknown',
                'seconds_since_last_pass' => null,
                'pid'                     => null,
                'interval_seconds'        => null,
                'mode'                    => null,
                'last_pass_utc'           => null,
            ];
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['last_pass_utc'])) {
            return [
                'state'                   => 'unknown',
                'seconds_since_last_pass' => null,
                'pid'                     => null,
                'interval_seconds'        => null,
                'mode'                    => null,
                'last_pass_utc'           => null,
            ];
        }

        try {
            $lastPassUtc = new DateTimeImmutable((string) $data['last_pass_utc']);
        } catch (Throwable) {
            return [
                'state'                   => 'unknown',
                'seconds_since_last_pass' => null,
                'pid'                     => null,
                'interval_seconds'        => null,
                'mode'                    => null,
                'last_pass_utc'           => null,
            ];
        }

        $currentTime = $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $elapsedSeconds = max(0, $currentTime->getTimestamp() - $lastPassUtc->getTimestamp());
        $intervalSeconds = (int) ($data['interval_seconds'] ?? 3);
        $thresholdSeconds = max(3 * $intervalSeconds, 15);

        $state = ($elapsedSeconds <= $thresholdSeconds) ? 'online' : 'stale';

        return [
            'state'                   => $state,
            'seconds_since_last_pass' => $elapsedSeconds,
            'pid'                     => isset($data['pid']) ? (int) $data['pid'] : null,
            'interval_seconds'        => $intervalSeconds,
            'mode'                    => isset($data['mode']) ? (string) $data['mode'] : null,
            'last_pass_utc'           => $lastPassUtc->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
