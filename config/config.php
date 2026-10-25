<?php
declare(strict_types=1);

/**
 * Sync Guard - Application Configuration Loader
 *
 * Reads configuration from environment variables (Docker / OS level),
 * or parses a root `.env` file if present. Sensitive credentials are never
 * hard-coded in source code; sensible defaults are provided for local development.
 */

// Lightweight .env parser for CLI / XAMPP / local environments without Docker
$envFile = dirname(__DIR__) . '/.env';
if (file_exists($envFile) && is_readable($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        // Ignore comments and empty lines
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (str_contains($line, '=')) {
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value, " \t\n\r\0\x0B\"'");
            // Set only if not already supplied by the OS or Docker
            if (getenv($key) === false) {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
    }
}

/**
 * Retrieve an environment variable with a safe fallback.
 */
$getEnv = function (string $key, string $default = ''): string {
    $val = getenv($key);
    if ($val !== false && $val !== '') {
        return $val;
    }
    if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
        return (string) $_ENV[$key];
    }
    if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
        return (string) $_SERVER[$key];
    }
    return $default;
};

return [
    'app' => [
        'env'   => $getEnv('APP_ENV', 'local'),
        'debug' => filter_var($getEnv('APP_DEBUG', 'true'), FILTER_VALIDATE_BOOLEAN),
    ],
    'db' => [
        'host'    => $getEnv('DB_HOST', 'db'),
        'port'    => (int) $getEnv('DB_PORT', '3306'),
        'name'    => $getEnv('DB_NAME', 'sync_guard'),
        'user'    => $getEnv('DB_USER', 'sync_user'),
        'pass'    => $getEnv('DB_PASS', 'sync_pass'),
        'charset' => 'utf8mb4',
    ],
    'adapter' => [
        'connect_timeout' => (int) $getEnv('ADAPTER_CONNECT_TIMEOUT_SECONDS', '2'),
        'timeout'         => (int) $getEnv('ADAPTER_TIMEOUT_SECONDS', '5'),
    ],
    'mock' => [
        'shared_secret'   => $getEnv('MOCK_SHARED_SECRET', 'dev_mock_shared_secret_7c9f8a2b4e6d1c3a'),
    ],
    'worker' => [
        'batch_size'          => (int) $getEnv('WORKER_BATCH_SIZE', '10'),
        'interval_seconds'    => (int) $getEnv('WORKER_INTERVAL_SECONDS', '3'),
        'stuck_after_seconds' => (int) $getEnv('WORKER_STUCK_AFTER_SECONDS', '300'),
    ],
    'retry' => [
        'jitter_percent'      => (int) $getEnv('RETRY_JITTER_PERCENT', '10'),
    ],
    'health' => [
        'window_attempts'     => (int) $getEnv('HEALTH_WINDOW_ATTEMPTS', '50'),
    ],
    'demo' => [
        'enabled' => filter_var($getEnv('DEMO_CONTROLS_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN),
    ],
    'insights' => [
        'window_hours' => (int) $getEnv('INSIGHTS_WINDOW_HOURS', '24'),
    ],
];

