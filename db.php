<?php
/**
 * The Slate — database connection and schema migration.
 *
 * Config lives outside the web root (see config.sample.php). The schema is
 * applied on demand, so there is nothing to run by hand after a deploy: the
 * first request creates the tables inside an advisory lock, and schema.sql is
 * written so re-running it is harmless.
 */

declare(strict_types=1);

/** Candidate config paths, most preferred first. */
function slate_config_paths(): array
{
    return [
        // Above the web root: /home/<user>/.slate-config.php
        dirname(__DIR__, 3) . '/.slate-config.php',
        dirname(__DIR__, 2) . '/.slate-config.php',
        __DIR__ . '/config.php',
    ];
}

function slate_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }
    foreach (slate_config_paths() as $path) {
        if (is_readable($path)) {
            $loaded = require $path;
            if (is_array($loaded)) {
                $config = $loaded;
                return $config;
            }
        }
    }
    throw new RuntimeException('no_config');
}

function slate_db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $c = slate_config();
    $dsn = sprintf(
        'pgsql:host=%s;port=%d;dbname=%s',
        $c['db_host'] ?? 'localhost',
        (int) ($c['db_port'] ?? 5432),
        $c['db_name'] ?? ''
    );
    $pdo = new PDO($dsn, $c['db_user'] ?? '', $c['db_pass'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    slate_migrate($pdo);
    return $pdo;
}

/**
 * Apply schema.sql once per process, guarded by a Postgres advisory lock so
 * two simultaneous first requests cannot race each other.
 */
function slate_migrate(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $exists = $pdo->query("SELECT to_regclass('public.users') IS NOT NULL AS ok")->fetch();
    if (!empty($exists['ok'])) {
        return;
    }

    $pdo->exec('SELECT pg_advisory_lock(8264773)');
    try {
        $again = $pdo->query("SELECT to_regclass('public.users') IS NOT NULL AS ok")->fetch();
        if (empty($again['ok'])) {
            $sql = file_get_contents(__DIR__ . '/schema.sql');
            if ($sql === false) {
                throw new RuntimeException('schema_missing');
            }
            $pdo->exec($sql);
        }
    } finally {
        $pdo->exec('SELECT pg_advisory_unlock(8264773)');
    }
}
