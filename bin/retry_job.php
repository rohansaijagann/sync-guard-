<?php
declare(strict_types=1);

/**
 * Sync Guard - Manual Job Retry CLI Utility
 *
 * Allows operations staff to manually re-queue failed or dead jobs.
 *
 * Usage:
 *   php bin/retry_job.php <job_id>
 *   php bin/retry_job.php --all-dead
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Error: bin/retry_job.php must be executed from the CLI.\n");
    exit(1);
}

// Autoloading
$vendorAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (file_exists($vendorAutoload)) {
    require_once $vendorAutoload;
} else {
    spl_autoload_register(function (string $class): void {
        $prefix = 'SyncGuard\\';
        if (str_starts_with($class, $prefix)) {
            $relativeClass = substr($class, strlen($prefix));
            $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', $relativeClass) . '.php';
            if (file_exists($file)) {
                require_once $file;
            }
        }
    });
}

use SyncGuard\Database\Connection;
use SyncGuard\Repositories\JobRepository;

$config = require dirname(__DIR__) . '/config/config.php';
$appEnv = (string) ($config['app']['env'] ?? 'production');
if (strtolower($appEnv) !== 'local') {
    fwrite(STDERR, sprintf("[ERROR] retry_job is only allowed in 'local' environment (current: '%s').\n", $appEnv));
    exit(1);
}

$pdo = Connection::get();
$jobRepo = new JobRepository($pdo);

$arg = $argv[1] ?? null;

if ($arg === null || in_array($arg, ['-h', '--help'], true)) {
    echo "Usage:\n";
    echo "  php bin/retry_job.php <job_id>       Queue a specific failed/dead job for retry\n";
    echo "  php bin/retry_job.php --all-dead     Queue all currently dead jobs for retry\n";
    exit(1);
}

// Option A: Retry all dead jobs
if ($arg === '--all-dead') {
    $count = $jobRepo->retryAllDead();
    echo sprintf("[OK] Queued %d dead jobs for immediate retry.\n", $count);
    exit(0);
}

// Option B: Retry a specific job by integer ID
if (!is_numeric($arg) || (int) $arg <= 0) {
    fwrite(STDERR, sprintf("[ERROR] Invalid job ID '%s'. Job ID must be a positive integer.\n", $arg));
    exit(1);
}

$jobId = (int) $arg;
$result = $jobRepo->retry($jobId);

switch ($result) {
    case 'retried':
        echo sprintf("[OK] Job #%d successfully reset to 'pending' with attempt_count = 0 and scheduled for immediate retry.\n", $jobId);
        exit(0);

    case 'not_found':
        fwrite(STDERR, sprintf("[ERROR] Job #%d does not exist.\n", $jobId));
        exit(1);

    case 'not_retryable':
    default:
        $stmt = $pdo->prepare('SELECT status FROM sync_jobs WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $jobId, PDO::PARAM_INT);
        $stmt->execute();
        $status = $stmt->fetchColumn() ?: 'unknown';

        $reason = match ($status) {
            'success'     => 'The job has already succeeded.',
            'pending'     => 'The job is already pending and waiting to be picked up.',
            'in_progress' => 'The job is currently claimed and being processed by a worker.',
            default       => "The job is in '{$status}' state and cannot be retried.",
        };

        fwrite(STDERR, sprintf("[ERROR] Cannot retry Job #%d: %s\n", $jobId, $reason));
        exit(1);
}
