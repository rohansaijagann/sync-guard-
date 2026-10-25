<?php

declare(strict_types=1);

namespace SyncGuard\Support;

/**
 * Class MockControl
 *
 * Centralized operational failure mode and latency simulation controller for Mock Sites B and C.
 * Shared across the CLI utility (bin/mock_control.php) and the HTTP Demo API (/api/demo.php).
 */
class MockControl
{
    public const ALLOWED_MODES = [
        'site_b' => ['normal', 'fast', 'slow', 'down', 'reject'],
        'site_c' => ['random', 'always_succeed', 'always_fail', 'always_reject', 'forget'],
    ];

    public const DEFAULT_STATE = [
        'site_b' => 'normal',
        'site_c' => 'random',
    ];

    public static function getStorageDir(): string
    {
        return dirname(__DIR__, 2) . '/storage/mock';
    }

    public static function getControlFile(): string
    {
        return self::getStorageDir() . '/control.json';
    }

    /**
     * Read current mock control modes with default fallback.
     *
     * @return array{site_b: string, site_c: string}
     */
    public static function read(): array
    {
        $file = self::getControlFile();
        if (!file_exists($file)) {
            return self::DEFAULT_STATE;
        }

        $raw = @file_get_contents($file);
        if ($raw === false) {
            return self::DEFAULT_STATE;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return self::DEFAULT_STATE;
        }

        $state = self::DEFAULT_STATE;
        if (isset($data['site_b']) && in_array($data['site_b'], self::ALLOWED_MODES['site_b'], true)) {
            $state['site_b'] = $data['site_b'];
        }
        if (isset($data['site_c']) && in_array($data['site_c'], self::ALLOWED_MODES['site_c'], true)) {
            $state['site_c'] = $data['site_c'];
        }

        return $state;
    }

    /**
     * Atomically persist mock control configuration.
     *
     * @param array<string, string> $state
     * @return bool
     */
    public static function write(array $state): bool
    {
        $dir = self::getStorageDir();
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
            @chmod($dir, 0777);
        }

        $controlFile = self::getControlFile();
        $tmpFile = tempnam($dir, 'ctl_');
        if ($tmpFile === false) {
            return false;
        }

        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        if (file_put_contents($tmpFile, $json) === false) {
            @unlink($tmpFile);
            return false;
        }

        @chmod($tmpFile, 0644);

        if (!rename($tmpFile, $controlFile)) {
            @unlink($tmpFile);
            return false;
        }

        @chmod($controlFile, 0644);
        return true;
    }

    /**
     * Set mode for a specific mock site.
     *
     * @param string $site 'site_b' | 'site_c'
     * @param string $mode
     * @return bool
     */
    public static function setMode(string $site, string $mode): bool
    {
        if (!isset(self::ALLOWED_MODES[$site]) || !in_array($mode, self::ALLOWED_MODES[$site], true)) {
            return false;
        }

        $state = self::read();
        $state[$site] = $mode;

        return self::write($state);
    }

    /**
     * Reset all mock modes to default state.
     *
     * @return bool
     */
    public static function reset(): bool
    {
        return self::write(self::DEFAULT_STATE);
    }

    /**
     * Alias for reset().
     *
     * @return bool
     */
    public static function resetDefaults(): bool
    {
        return self::reset();
    }

    /**
     * Get current mode for a specific site.
     *
     * @param string $site
     * @return string
     */
    public static function getMode(string $site): string
    {
        $state = self::read();
        return $state[$site] ?? (self::DEFAULT_STATE[$site] ?? 'normal');
    }

    /**
     * Clear mock storage history files.
     *
     * @return array{site_b_cleared: bool, site_c_cleared: bool}
     */
    public static function clearStorage(): array
    {
        $dir = self::getStorageDir();
        $fileB = $dir . '/site_b.json';
        $fileC = $dir . '/site_c.json';

        $b = file_exists($fileB) ? @unlink($fileB) : true;
        $c = file_exists($fileC) ? @unlink($fileC) : true;

        return ['site_b_cleared' => $b, 'site_c_cleared' => $c];
    }
}
