<?php
/**
 * The Slate — temporary server capability probe.
 *
 * Reports what this host can actually do, so the PostgreSQL work is planned
 * against facts rather than assumptions about the hosting plan.
 *
 * Sits behind the basic auth on /filmmaking/, and reports capabilities only —
 * no credentials, no connection attempts, no database contents.
 *
 * DELETE THIS FILE once the answers are recorded.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function ini_or_null(string $key): ?string
{
    $value = ini_get($key);
    return $value === false ? null : (string) $value;
}

$saved = realpath(__DIR__ . '/../saved');

echo json_encode([
    'php' => [
        'version' => PHP_VERSION,
        'sapi' => PHP_SAPI,
        'json_validate' => function_exists('json_validate'),
    ],

    // The gate: a PostgreSQL server on the plan is no use if PHP cannot reach
    // it. pdo_pgsql is what the app would use.
    'postgres' => [
        'pdo_pgsql' => extension_loaded('pdo_pgsql'),
        'pgsql' => extension_loaded('pgsql'),
        'pdo_drivers' => class_exists('PDO') ? PDO::getAvailableDrivers() : [],
    ],

    // Useful for the auth work: sessions, outbound calls, password hashing.
    'auth_support' => [
        'session' => extension_loaded('session'),
        'openssl' => extension_loaded('openssl'),
        'curl' => extension_loaded('curl'),
        'allow_url_fopen' => (bool) ini_get('allow_url_fopen'),
        'argon2id' => defined('PASSWORD_ARGON2ID'),
        'session_save_path' => ini_or_null('session.save_path'),
    ],

    'limits' => [
        'post_max_size' => ini_or_null('post_max_size'),
        'upload_max_filesize' => ini_or_null('upload_max_filesize'),
        'memory_limit' => ini_or_null('memory_limit'),
        'max_execution_time' => ini_or_null('max_execution_time'),
        'user_ini_applied' => ini_or_null('post_max_size') !== '8M',
    ],

    'storage' => [
        'saved_dir_found' => $saved !== false,
        'saved_writable' => $saved !== false && is_writable($saved),
        'saved_htaccess_installed' => $saved !== false && file_exists($saved . '/.htaccess'),
        'boards_on_disk' => $saved === false ? 0 : count(array_filter(
            glob($saved . '/*.json') ?: [],
            static fn(string $p): bool => substr(basename($p, '.json'), -5) !== '.meta'
        )),
        'disk_free_mb' => $saved === false ? null : (int) round((float) disk_free_space($saved) / 1048576),
    ],

    'auth_now' => [
        'user' => $_SERVER['PHP_AUTH_USER'] ?? $_SERVER['REMOTE_USER'] ?? $_SERVER['REDIRECT_REMOTE_USER'] ?? null,
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
