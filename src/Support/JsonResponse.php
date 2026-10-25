<?php

declare(strict_types=1);

namespace SyncGuard\Support;

/**
 * Class JsonResponse
 *
 * Standardized JSON response helper for API endpoints.
 * Ensures consistent output format, proper HTTP status codes,
 * explicit UTF-8 headers, and uniform error structures.
 */
class JsonResponse
{
    /**
     * Send a standardized JSON response and terminate script execution.
     *
     * @param array<string, mixed> $payload The complete response body array
     * @param int $statusCode HTTP status code (default 200)
     * @return never
     */
    public static function send(array $payload, int $statusCode = 200): never
    {
        // Clear any previous output buffers to guarantee clean JSON emission
        if (ob_get_level() > 0) {
            ob_clean();
        }

        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');

        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit(0);
    }

    /**
     * Send a standardized successful JSON response.
     * Format: { "ok": true, "data": { ... } }
     *
     * @param array<array-key, mixed> $data Payload data
     * @param int $statusCode HTTP status code (e.g. 200 OK or 201 Created)
     * @return never
     */
    public static function success(array $data, int $statusCode = 200): never
    {
        self::send([
            'ok'   => true,
            'data' => $data,
        ], $statusCode);
    }

    /**
     * Send a standardized failure JSON response.
     * Format: { "ok": false, "error": "...", "fields": { "field": "..." } }
     *
     * @param string $message Plain-language error description
     * @param int $statusCode HTTP error status code (e.g. 400, 405, 409, 422, 500)
     * @param array<string, string> $fields Field-specific validation errors
     * @return never
     */
    public static function error(string $message, int $statusCode = 400, array $fields = []): never
    {
        self::send([
            'ok'     => false,
            'error'  => $message,
            'fields' => (object) $fields, // Ensures empty array serializes to {} in JSON
        ], $statusCode);
    }
}
