<?php
declare(strict_types=1);

/**
 * Sync Guard - Health Check Endpoint
 *
 * Returns JSON response indicating system health and database connectivity.
 * Adheres strictly to security guidelines: never leaks credentials or stack traces.
 */

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

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

try {
    $config = require dirname(__DIR__) . '/config/config.php';
    $dbName = $config['db']['name'] ?? 'sync_guard';

    $pdo = Connection::get();

    // Verify database connectivity and count existing application tables
    $stmt = $pdo->prepare('
        SELECT COUNT(*) 
        FROM information_schema.tables 
        WHERE TABLE_SCHEMA = :db
    ');
    $stmt->execute([':db' => $dbName]);
    $tableCount = (int) $stmt->fetchColumn();

    http_response_code(200);
    echo json_encode([
        'ok'     => true,
        'db'     => 'connected',
        'tables' => $tableCount,
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;

} catch (Throwable $e) {
    // Return 503 Service Unavailable without exposing sensitive details
    http_response_code(503);
    echo json_encode([
        'ok'    => false,
        'db'    => 'disconnected',
        'error' => 'Database service unavailable',
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}
