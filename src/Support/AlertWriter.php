<?php

declare(strict_types=1);

namespace SyncGuard\Support;

use Throwable;

/**
 * Class AlertWriter
 *
 * Appends diagnostic and critical event logs to `storage/alerts.log` in JSON Lines format.
 * Employs advisory locking (flock) to prevent log entry interleaving between concurrent processes.
 *
 * FAULT ISOLATION:
 * Alert writing is considered a non-fatal telemetry side-effect. If the filesystem is read-only,
 * disk is full, or permissions fail, the failure is logged to PHP's error_log() and swallowed.
 * An alerting failure must NEVER roll back or crash the background worker loop.
 */
class AlertWriter
{
    private readonly string $logPath;

    public function __construct(?string $logPath = null)
    {
        $this->logPath = $logPath ?? dirname(__DIR__, 2) . '/storage/alerts.log';
    }

    /**
     * Record a critical dead job event.
     *
     * @param int $jobId Primary key of the sync job
     * @param int $updateId Associated inventory update ID
     * @param string $channel Name of the target OTA channel
     * @param int $attemptCount Total attempts made before dying
     * @param string|null $lastError Diagnostic error reason
     * @return bool True if written successfully, false otherwise
     */
    public function writeJobDead(
        int $jobId,
        int $updateId,
        string $channel,
        int $attemptCount,
        ?string $lastError
    ): bool {
        return $this->write([
            'timestamp'     => gmdate('Y-m-d\TH:i:s\Z'),
            'level'         => 'critical',
            'event'         => 'job_dead',
            'job_id'        => $jobId,
            'update_id'     => $updateId,
            'channel'       => $channel,
            'attempt_count' => $attemptCount,
            'last_error'    => $lastError ?? 'Unknown delivery failure',
        ]);
    }

    /**
     * Append a single JSON line with advisory file locking.
     *
     * @param array<string, mixed> $payload
     * @return bool
     */
    public function write(array $payload): bool
    {
        try {
            $dir = dirname($this->logPath);
            if (!is_dir($dir)) {
                if (!@mkdir($dir, 0777, true) && !is_dir($dir)) {
                    error_log(sprintf('AlertWriter: Failed to create storage directory "%s".', $dir));
                    return false;
                }
            }

            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                error_log('AlertWriter: Failed to encode alert payload to JSON.');
                return false;
            }

            $line = $json . "\n";
            $isNew = !file_exists($this->logPath);

            $fp = @fopen($this->logPath, 'a');
            if (!$fp) {
                error_log(sprintf('AlertWriter: Failed to open alert log file "%s" for writing.', $this->logPath));
                return false;
            }

            flock($fp, LOCK_EX);
            fwrite($fp, $line);
            fflush($fp);
            flock($fp, LOCK_UN);
            fclose($fp);

            if ($isNew) {
                @chmod($this->logPath, 0666);
            }

            return true;
        } catch (Throwable $e) {
            error_log(sprintf('AlertWriter exception: %s', $e->getMessage()));
            return false;
        }
    }
}
