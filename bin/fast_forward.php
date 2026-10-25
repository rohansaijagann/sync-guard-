<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
use SyncGuard\Database\Connection;
use SyncGuard\Repositories\JobRepository;

$config = require dirname(__DIR__) . '/config/config.php';
$appEnv = (string) ($config['app']['env'] ?? 'production');
if ($appEnv !== 'local') {
    fwrite(STDERR, sprintf("[ERROR] fast_forward is only allowed in 'local' environment (current: '%s').\n", $appEnv));
    exit(1);
}

$jobId = isset($argv[1]) && is_numeric($argv[1]) ? (int) $argv[1] : null;
$pdo = Connection::get();
$jobRepo = new JobRepository($pdo);

$affected = $jobRepo->skipWait($jobId);

if ($jobId !== null) {
    echo sprintf("[TIME-TRAVEL] Job #%d next_attempt_at moved into the past.\n", $jobId);
} else {
    echo sprintf("[TIME-TRAVEL] %d failed job(s) moved into the past (due immediately).\n", $affected);
}


