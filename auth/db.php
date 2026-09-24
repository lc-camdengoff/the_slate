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
function fm_config_paths(): array
{
    $paths = [];
    // Above the web root: /home/<user>/.filmmaking-config.php
    foreach ([3, 2, 4] as $up) {
        $paths[] = dirname(__DIR__, $up) . '/.filmmaking-config.php';
    }
    // The name this used before accounts were shared between tools.
    foreach ([3, 2, 4] as $up) {
        $paths[] = dirname(__DIR__, $up) . '/.slate-config.php';
    }
    $paths[] = __DIR__ . '/config.php';
    return $paths;
}

function fm_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }
    foreach (fm_config_paths() as $path) {
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

function fm_db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $c = fm_config();
    $dsn = sprintf(
        'pgsql:host=%s;port=%d;dbname=%s',
        $c['db_host'] ?? 'localhost',
        (int) ($c['db_port'] ?? 5432),
        $c['db_name'] ?? ''
    );
    // Some hosts only accept TLS connections (a hostssl line in pg_hba.conf).
    // Without this, such a server refuses with "no pg_hba.conf entry ... no
    // encryption", which reads like a permissions problem and is not one.
    $sslmode = trim((string) ($c['db_sslmode'] ?? ''));
    if ($sslmode !== '' && preg_match('/^[a-z-]{4,12}$/', $sslmode)) {
        $dsn .= ';sslmode=' . $sslmode;
    }
    $pdo = new PDO($dsn, $c['db_user'] ?? '', $c['db_pass'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    fm_migrate($pdo);
    return $pdo;
}

/**
 * Apply schema.sql once per process, guarded by a Postgres advisory lock so
 * two simultaneous first requests cannot race each other.
 */
function fm_migrate(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $exists = $pdo->query("SELECT to_regclass('public.users') IS NOT NULL AS ok")->fetch();
    if (!empty($exists['ok'])) {
        fm_migrate_columns($pdo);
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

/**
 * Bring an existing database up to date.
 *
 * schema.sql only runs on a database that has no users table, so a column
 * added later would never reach an install that already exists. These are
 * checked rather than blindly re-applied: ALTER TABLE takes an exclusive lock
 * even when it turns out to be a no-op, and this runs on a live site.
 */
function fm_migrate_columns(PDO $pdo): void
{
    $stmt = $pdo->query(
        "SELECT count(*) AS n FROM information_schema.columns
          WHERE table_schema = 'public' AND table_name = 'users' AND column_name = 'email'"
    );
    if ((int) ($stmt->fetch()['n'] ?? 0) > 0) {
        return;
    }

    $pdo->exec('SELECT pg_advisory_lock(8264774)');
    try {
        $pdo->exec('ALTER TABLE users ADD COLUMN IF NOT EXISTS email text');
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS users_email_lower_idx
                        ON users (lower(email)) WHERE email IS NOT NULL');
    } finally {
        $pdo->exec('SELECT pg_advisory_unlock(8264774)');
    }
}
