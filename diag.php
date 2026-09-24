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

/**
 * Say why the connection failed, without repeating the driver's own words.
 *
 * The raw message can carry the host, port and user, so it is matched against
 * known failures and answered with fixed text of our own. Nothing from the
 * exception is echoed.
 */
function slate_db_diagnosis(Throwable $e): array
{
    $raw = strtolower($e->getMessage());
    $state = (string) $e->getCode();

    $checks = [
        ['cause' => 'bad_credentials',
         'fix' => 'The database username or password is wrong. Check db_user against the user cPanel created (it is prefixed separately from the database) and re-set its password if unsure.',
         'match' => ['password authentication failed', '28p01']],

        ['cause' => 'no_such_database',
         'fix' => 'That database name does not exist. Copy db_name exactly as cPanel shows it.',
         'match' => ['does not exist', '3d000']],

        // A server that wants TLS and one that will not accept this host at
        // all give the same "no pg_hba.conf entry ... no encryption" wording,
        // so the message cannot tell them apart. The connection attempts below
        // do that instead.
        ['cause' => 'connection_refused_by_rule',
         'fix' => 'The server is running but refused this connection. It is about how you connect, not about privileges — see "attempts" for which settings do work.',
         'match' => ['no pg_hba.conf entry', 'no encryption', '28000']],

        ['cause' => 'server_unreachable',
         'fix' => 'Nothing is answering at that host and port. Try db_host 127.0.0.1 instead of localhost, and check the port on the PostgreSQL Databases page.',
         'match' => ['could not connect', 'connection refused', 'no such file or directory',
                     'could not translate host', '08006', '08001', '08004']],

        ['cause' => 'no_privileges',
         'fix' => 'The user connected but is not allowed to use the database. Grant it ALL privileges in cPanel.',
         'match' => ['permission denied', '42501']],
    ];

    foreach ($checks as $check) {
        foreach ($check['match'] as $needle) {
            if (strpos($raw, $needle) !== false || $state === strtoupper($needle)) {
                return ['cause' => $check['cause'], 'fix' => $check['fix']];
            }
        }
    }
    return [
        'cause' => 'unknown',
        'fix' => 'The connection failed for a reason we do not recognise. The full message is in the PHP error log — look for an error_log file in this folder.',
    ];
}

/**
 * Try the usual connection variants and report which ones work.
 *
 * PostgreSQL's refusal messages cannot distinguish "wants TLS" from "will not
 * accept this host", so rather than guess, connect each way and say which
 * succeeded. Only the variant labels and outcomes are reported — never the
 * host, user or password.
 */
function slate_connection_attempts(): array
{
    if (!function_exists('fm_config')) {
        return [];
    }
    try {
        $c = fm_config();
    } catch (Throwable $e) {
        return [];
    }

    $configured = (string) ($c['db_host'] ?? 'localhost');
    $variants = [
        'as configured' => [],
        'db_sslmode => require' => ['sslmode' => 'require'],
        'db_host => 127.0.0.1' => ['host' => '127.0.0.1'],
        'db_host => 127.0.0.1, sslmode require' => ['host' => '127.0.0.1', 'sslmode' => 'require'],
    ];
    // "localhost" is a hostname to libpq, not the Unix socket — it resolves to
    // TCP on 127.0.0.1, so swapping the two barely differs. The socket is used
    // only when the host is a directory, and on a shared host that is often
    // the only connection the server permits.
    foreach (['/var/run/postgresql', '/tmp', '/var/lib/postgresql'] as $dir) {
        $variants['db_host => ' . $dir . ' (socket)'] = ['host' => $dir];
    }

    $results = [];
    foreach ($variants as $label => $over) {
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;connect_timeout=5',
            $over['host'] ?? $configured,
            (int) ($c['db_port'] ?? 5432),
            (string) ($c['db_name'] ?? '')
        );
        if (isset($over['sslmode'])) {
            $dsn .= ';sslmode=' . $over['sslmode'];
        }
        try {
            new PDO($dsn, (string) ($c['db_user'] ?? ''), (string) ($c['db_pass'] ?? ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $results[$label] = 'CONNECTED — use this';
        } catch (Throwable $e) {
            $results[$label] = slate_db_diagnosis($e)['cause'];
        }
    }
    return $results;
}

$allowed = false;
$firstRun = null;
$dbError = null;
$dbDiagnosis = null;

try {
    require __DIR__ . '/lib.php';
    require __DIR__ . '/../auth/auth.php';
    $firstRun = fm_user_count() === 0;
    $user = fm_current_user();
    // Open during setup, admin-only once the team exists.
    $allowed = $firstRun || ($user !== null && $user['is_admin']);
} catch (Throwable $e) {
    // No config or no database: answer the PHP-only questions, which is what
    // you are here to ask at that point.
    if ($e instanceof RuntimeException && $e->getMessage() === 'no_config') {
        $dbError = 'no_config';
    } else {
        $dbError = 'database_unreachable';
        $dbDiagnosis = slate_db_diagnosis($e);
    }
}

if ($dbError !== null) {
    $body = [
        'ok' => false,
        'error' => $dbError,
        'note' => 'Database not reachable yet, so only PHP capabilities are shown.',
    ];
    if ($dbDiagnosis !== null) {
        $body['cause'] = $dbDiagnosis['cause'];
        $body['fix'] = $dbDiagnosis['fix'];
        $body['attempts'] = slate_connection_attempts();
    }
    echo json_encode($body + slate_public_diag(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
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
    'users' => fm_user_count(),
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
