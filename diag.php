<?php
/**
 * The Slate — server capability probe.
 *
 * Reports what this host can do, for checking an install. Basic auth no longer
 * guards this folder, so it gates itself: readable during first-time setup
 * (while no accounts exist) and by admins afterwards. If the database cannot
 * be reached it answers with the PHP-only subset, since that is exactly the
 * case you need it for, and says nothing about paths or storage.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function ini_or_null(string $key): ?string
{
    $value = ini_get($key);
    return $value === false ? null : (string) $value;
}

/** Capability facts that give nothing away about this server's layout. */
function slate_public_diag(): array
{
    return [
        'php' => [
            'version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'json_validate' => function_exists('json_validate'),
        ],
        // The gate for the accounts work: a PostgreSQL server on the plan is
        // no use if PHP cannot reach it.
        'postgres' => [
            'pdo_pgsql' => extension_loaded('pdo_pgsql'),
            'pgsql' => extension_loaded('pgsql'),
            'pdo_drivers' => class_exists('PDO') ? PDO::getAvailableDrivers() : [],
        ],
        'auth_support' => [
            'session' => extension_loaded('session'),
            'openssl' => extension_loaded('openssl'),
            'argon2id' => defined('PASSWORD_ARGON2ID'),
        ],
        'limits' => [
            'post_max_size' => ini_or_null('post_max_size'),
            'upload_max_filesize' => ini_or_null('upload_max_filesize'),
            'memory_limit' => ini_or_null('memory_limit'),
            'max_execution_time' => ini_or_null('max_execution_time'),
            'user_ini_applied' => ini_or_null('post_max_size') !== '8M',
        ],
    ];
}

$allowed = false;
$firstRun = null;
$dbError = null;

try {
    require __DIR__ . '/lib.php';
    require __DIR__ . '/auth.php';
    $firstRun = slate_user_count() === 0;
    $user = slate_current_user();
    // Open during setup, admin-only once the team exists.
    $allowed = $firstRun || ($user !== null && $user['is_admin']);
} catch (Throwable $e) {
    // No config or no database: answer the PHP-only questions, which is what
    // you are here to ask at that point.
    $dbError = $e instanceof RuntimeException && $e->getMessage() === 'no_config'
        ? 'no_config'
        : 'database_unreachable';
}

if ($dbError !== null) {
    echo json_encode([
        'ok' => false,
        'error' => $dbError,
        'note' => 'Database not reachable yet, so only PHP capabilities are shown.',
    ] + slate_public_diag(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!$allowed) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'admins_only']);
    exit;
}

$saved = slate_saved_dir();
$report = slate_public_diag();
$report['ok'] = true;
$report['accounts'] = [
    'users' => slate_user_count(),
    'first_run' => $firstRun,
];
$report['storage'] = [
    'saved_dir_found' => $saved !== null,
    'saved_writable' => $saved !== null && is_writable($saved),
    'saved_htaccess_installed' => $saved !== null && file_exists($saved . '/.htaccess'),
    'boards_on_disk' => $saved === null ? 0 : count(array_filter(
        glob($saved . '/*.json') ?: [],
        static fn(string $p): bool => substr(basename($p, '.json'), -5) !== '.meta'
    )),
    'disk_free_mb' => $saved === null ? null : (int) round((float) disk_free_space($saved) / 1048576),
];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
