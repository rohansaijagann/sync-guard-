<?php
declare(strict_types=1);

/**
 * Sync Guard - Helper CLI Update Submitter
 *
 * Submits an inventory update payload to the local API endpoint.
 *
 * Usage:
 *   php bin/demo_submit.php [room_id] [price] [available_units]
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Error: bin/demo_submit.php must be run from the CLI.\n");
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

use SyncGuard\Support\Uuid;

$config = require dirname(__DIR__) . '/config/config.php';
$appEnv = (string) ($config['app']['env'] ?? 'production');
if ($appEnv !== 'local') {
    fwrite(STDERR, sprintf("[ERROR] demo_submit is only allowed in 'local' environment (current: '%s').\n", $appEnv));
    exit(1);
}

$port = (string) ($_SERVER['SERVER_PORT'] ?? '80');
$url = 'http://127.0.0.1/api/inventory.php';

$roomId = isset($argv[1]) && is_numeric($argv[1]) ? (int) $argv[1] : 1;
$price = isset($argv[2]) && is_numeric($argv[2]) ? (float) $argv[2] : 12500.0;
$units = isset($argv[3]) && is_numeric($argv[3]) ? (int) $argv[3] : 5;

$today = SyncGuard\Support\Time::todayInTokyo();
$nextWeek = date('Y-m-d', strtotime('+4 days', strtotime($today)));

$payload = [
    'room_id'         => $roomId,
    'date_from'       => $today,
    'date_to'         => $nextWeek,
    'available_units' => $units,
    'price'           => $price,
    'idempotency_key' => Uuid::v4(),
];

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 5,
]);

$response = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo sprintf("HTTP %d: %s\n", $code, $response ?: 'No response');
