<?php
/**
 * The Slate — accounts and sessions.
 *
 * Replaces the cPanel basic auth that used to guard /filmmaking/. Signup is
 * self-service, gated by a shared invite code; there is no outbound mail on
 * this host, so password resets are one-time codes an admin hands over rather
 * than emailed links.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

const SLATE_COOKIE = 'slate_session';
const SLATE_TOKEN_BYTES = 32;

// Throttles, per 15 minutes.
const SLATE_MAX_LOGIN_PER_IP = 20;
const SLATE_MAX_LOGIN_PER_USER = 8;
const SLATE_MAX_SIGNUP_PER_IP = 6;
const SLATE_THROTTLE_WINDOW = '15 minutes';

const SLATE_MIN_PASSWORD = 10;

/**
 * Turn any uncaught error into a bland response.
 *
 * Driver messages can carry the connection string, and a stack trace names
 * paths, so the detail goes to the error log and never to the browser.
 */
function slate_install_error_handler(string $as = 'json'): void
{
    set_exception_handler(static function (Throwable $e) use ($as): void {
        error_log('slate: ' . get_class($e) . ': ' . $e->getMessage());
        if (headers_sent()) {
            return;
        }
        http_response_code(500);
        header('Cache-Control: no-store');
        if ($as === 'json') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'server_error']);
            return;
        }
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<title>Something went wrong</title></head><body '
            . 'style="font:15px/1.6 -apple-system,BlinkMacSystemFont,Segoe UI,Helvetica,sans-serif;'
            . 'padding:48px;color:#1D242B">'
            . '<h1 style="font-size:20px;margin:0 0 10px">Something went wrong</h1>'
            . '<p>The server hit an error handling that request. If it keeps '
            . 'happening, let an admin know.</p></body></html>';
    });
}

function slate_password_algo(): string
{
    return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
}

function slate_client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

function slate_is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

/** Directory this app is served from, e.g. /filmmaking/slate/ */
function slate_base_path(): string
{
    $dir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    return ($dir === '' ? '' : $dir) . '/';
}

// ---------------------------------------------------------------------------
// Throttling
// ---------------------------------------------------------------------------

/**
 * With emulated prepares off, PDO hands a PHP false to Postgres as an empty
 * string, which is not valid for a boolean column. Bind them by hand.
 */
function slate_bind_bool(PDOStatement $stmt, int $position, bool $value): void
{
    $stmt->bindValue($position, $value, PDO::PARAM_BOOL);
}

function slate_record_attempt(string $kind, string $subject, bool $ok): void
{
    $stmt = slate_db()->prepare(
        'INSERT INTO auth_attempts (kind, ip, subject, ok) VALUES (?, ?, ?, ?)'
    );
    $stmt->bindValue(1, $kind);
    $stmt->bindValue(2, slate_client_ip());
    $stmt->bindValue(3, substr($subject, 0, 120));
    slate_bind_bool($stmt, 4, $ok);
    $stmt->execute();
}

function slate_recent_failures(string $kind, string $column, string $value): int
{
    $sql = sprintf(
        'SELECT count(*) AS n FROM auth_attempts
          WHERE kind = ? AND %s = ? AND ok = false AND at > now() - interval %s',
        $column === 'ip' ? 'ip' : 'subject',
        "'" . SLATE_THROTTLE_WINDOW . "'"
    );
    $stmt = slate_db()->prepare($sql);
    $stmt->execute([$kind, $value]);
    return (int) ($stmt->fetch()['n'] ?? 0);
}

/** True when this client has burned through its allowance. */
function slate_throttled(string $kind, string $subject = ''): bool
{
    if ($kind === 'signup') {
        return slate_recent_failures('signup', 'ip', slate_client_ip()) >= SLATE_MAX_SIGNUP_PER_IP;
    }
    if (slate_recent_failures($kind, 'ip', slate_client_ip()) >= SLATE_MAX_LOGIN_PER_IP) {
        return true;
    }
    return $subject !== ''
        && slate_recent_failures($kind, 'subject', strtolower($subject)) >= SLATE_MAX_LOGIN_PER_USER;
}

// ---------------------------------------------------------------------------
// Sessions
// ---------------------------------------------------------------------------

function slate_session_days(): int
{
    return max(1, (int) (slate_config()['session_days'] ?? 30));
}

function slate_start_session(int $userId): void
{
    $token = bin2hex(random_bytes(SLATE_TOKEN_BYTES));
    $stmt = slate_db()->prepare(
        'INSERT INTO sessions (token_hash, user_id, expires_at, ip, user_agent)
         VALUES (?, ?, now() + (? || \' days\')::interval, ?, ?)'
    );
    $stmt->execute([
        hash('sha256', $token),
        $userId,
        (string) slate_session_days(),
        slate_client_ip(),
        substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300),
    ]);

    setcookie(SLATE_COOKIE, $token, [
        'expires' => time() + slate_session_days() * 86400,
        'path' => slate_base_path(),
        'secure' => slate_is_https(),
        'httponly' => true,
        // Lax keeps the cookie off cross-site POSTs, which is most of CSRF
        // handled before the token check below even runs.
        'samesite' => 'Lax',
    ]);
}

/** The signed-in user, or null. Extends the session as a side effect. */
function slate_current_user(): ?array
{
    static $user = null;
    static $looked = false;
    if ($looked) {
        return $user;
    }
    $looked = true;

    $token = (string) ($_COOKIE[SLATE_COOKIE] ?? '');
    if ($token === '' || !preg_match('/^[0-9a-f]{64}$/', $token)) {
        return null;
    }

    $stmt = slate_db()->prepare(
        'SELECT u.* FROM sessions s
           JOIN users u ON u.id = s.user_id
          WHERE s.token_hash = ? AND s.expires_at > now() AND u.is_active'
    );
    $stmt->execute([hash('sha256', $token)]);
    $found = $stmt->fetch();
    if (!$found) {
        return null;
    }

    $touch = slate_db()->prepare(
        'UPDATE sessions
            SET last_seen_at = now(), expires_at = now() + (? || \' days\')::interval
          WHERE token_hash = ?'
    );
    $touch->execute([(string) slate_session_days(), hash('sha256', $token)]);

    $user = $found;
    return $user;
}

function slate_end_session(): void
{
    $token = (string) ($_COOKIE[SLATE_COOKIE] ?? '');
    if ($token !== '' && preg_match('/^[0-9a-f]{64}$/', $token)) {
        $stmt = slate_db()->prepare('DELETE FROM sessions WHERE token_hash = ?');
        $stmt->execute([hash('sha256', $token)]);
    }
    setcookie(SLATE_COOKIE, '', [
        'expires' => time() - 3600,
        'path' => slate_base_path(),
        'secure' => slate_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/** Drop every session for a user — used after a password change. */
function slate_end_all_sessions(int $userId, bool $keepCurrent = false): void
{
    $token = (string) ($_COOKIE[SLATE_COOKIE] ?? '');
    if ($keepCurrent && preg_match('/^[0-9a-f]{64}$/', $token)) {
        $stmt = slate_db()->prepare('DELETE FROM sessions WHERE user_id = ? AND token_hash <> ?');
        $stmt->execute([$userId, hash('sha256', $token)]);
        return;
    }
    $stmt = slate_db()->prepare('DELETE FROM sessions WHERE user_id = ?');
    $stmt->execute([$userId]);
}

/** Opportunistic cleanup; cheap enough to run on login. */
function slate_prune(): void
{
    $db = slate_db();
    $db->exec('DELETE FROM sessions WHERE expires_at < now()');
    $db->exec("DELETE FROM auth_attempts WHERE at < now() - interval '7 days'");
    $db->exec("DELETE FROM password_resets WHERE expires_at < now() - interval '7 days'");
}

// ---------------------------------------------------------------------------
// CSRF
// ---------------------------------------------------------------------------

/**
 * A CSRF token bound to the session cookie, so it needs no server-side state.
 * Same-origin JavaScript can read the cookie to echo it back; a cross-site
 * page cannot.
 */
function slate_csrf_token(): string
{
    $token = (string) ($_COOKIE[SLATE_COOKIE] ?? '');
    if ($token === '') {
        return '';
    }
    return hash_hmac('sha256', 'csrf', $token);
}

function slate_check_csrf(?string $given): bool
{
    $expected = slate_csrf_token();
    return $expected !== '' && is_string($given) && hash_equals($expected, $given);
}

// ---------------------------------------------------------------------------
// Accounts
// ---------------------------------------------------------------------------

function slate_find_user(string $username): ?array
{
    $stmt = slate_db()->prepare('SELECT * FROM users WHERE username_ci = ?');
    $stmt->execute([strtolower(trim($username))]);
    return $stmt->fetch() ?: null;
}

function slate_user_count(): int
{
    return (int) (slate_db()->query('SELECT count(*) AS n FROM users')->fetch()['n'] ?? 0);
}

function slate_valid_username(string $username): bool
{
    return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{2,31}$/', $username) === 1;
}

/**
 * Validate an invite code and reserve one use.
 *
 * Returns true only if the code exists, is active, unexpired and has uses
 * left. The increment is conditional in SQL so two people redeeming the last
 * use of a code cannot both win.
 */
function slate_redeem_invite(string $code): bool
{
    $stmt = slate_db()->prepare(
        'UPDATE invite_codes
            SET uses = uses + 1
          WHERE code = ?
            AND is_active
            AND (expires_at IS NULL OR expires_at > now())
            AND (max_uses IS NULL OR uses < max_uses)'
    );
    $stmt->execute([$code]);
    return $stmt->rowCount() === 1;
}

/**
 * Hand back a use reserved by slate_redeem_invite() when the signup that
 * claimed it then failed — otherwise a typo'd username would quietly burn a
 * use of a limited code.
 */
function slate_release_invite(string $code): void
{
    $stmt = slate_db()->prepare(
        'UPDATE invite_codes SET uses = uses - 1 WHERE code = ? AND uses > 0'
    );
    $stmt->execute([$code]);
}

/**
 * Create an account.
 *
 * @return array{0:bool,1:string} [ok, error key]
 */
function slate_create_user(string $username, string $displayName, string $password, bool $isAdmin): array
{
    if (!slate_valid_username($username)) {
        return [false, 'bad_username'];
    }
    if (strlen($password) < SLATE_MIN_PASSWORD) {
        return [false, 'weak_password'];
    }
    $displayName = trim($displayName) !== '' ? trim($displayName) : $username;

    try {
        $stmt = slate_db()->prepare(
            'INSERT INTO users (username, username_ci, display_name, password_hash, is_admin)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->bindValue(1, trim($username));
        $stmt->bindValue(2, strtolower(trim($username)));
        $stmt->bindValue(3, substr($displayName, 0, 80));
        $stmt->bindValue(4, password_hash($password, slate_password_algo()));
        slate_bind_bool($stmt, 5, $isAdmin);
        $stmt->execute();
    } catch (PDOException $e) {
        // 23505 = unique_violation
        if ($e->getCode() === '23505') {
            return [false, 'username_taken'];
        }
        throw $e;
    }
    return [true, ''];
}

/**
 * Check a password and, on success, start a session.
 *
 * @return array{0:bool,1:string} [ok, error key]
 */
function slate_attempt_login(string $username, string $password): array
{
    if (slate_throttled('login', $username)) {
        slate_record_attempt('login', $username, false);
        return [false, 'throttled'];
    }

    $user = slate_find_user($username);
    // Hash even when the user is missing, so a bad username and a bad password
    // take about the same time.
    $hash = $user['password_hash'] ?? '$2y$10$usernamedoesnotexistpaddingpaddingpaddingpaddingpaddingpad';
    $ok = password_verify($password, $hash);

    if (!$user || !$ok || !$user['is_active']) {
        slate_record_attempt('login', $username, false);
        return [false, $user && $ok && !$user['is_active'] ? 'disabled' : 'bad_credentials'];
    }

    if (password_needs_rehash($hash, slate_password_algo())) {
        $stmt = slate_db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([password_hash($password, slate_password_algo()), $user['id']]);
    }

    slate_start_session((int) $user['id']);
    slate_db()->prepare('UPDATE users SET last_login_at = now() WHERE id = ?')
        ->execute([$user['id']]);
    slate_record_attempt('login', $username, true);
    slate_prune();
    return [true, ''];
}

function slate_set_password(int $userId, string $password): bool
{
    if (strlen($password) < SLATE_MIN_PASSWORD) {
        return false;
    }
    $stmt = slate_db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
    $stmt->execute([password_hash($password, slate_password_algo()), $userId]);
    return true;
}

// ---------------------------------------------------------------------------
// Gatekeeping for the JSON endpoints
// ---------------------------------------------------------------------------

/**
 * Require a signed-in user for an API request, answering 401 as JSON when
 * there is none. Returns the user row.
 */
function slate_require_api_user(): array
{
    $user = slate_current_user();
    if ($user === null) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['ok' => false, 'error' => 'not_signed_in']);
        exit;
    }
    return $user;
}

/**
 * Require a signed-in user *and* proof the request came from our own page.
 *
 * The SameSite=Lax cookie already keeps credentials off cross-site POSTs; the
 * header is the belt to that braces, since a cross-origin caller cannot set a
 * custom header without a preflight we never answer.
 */
function slate_require_api_write(): array
{
    $user = slate_require_api_user();
    $given = $_SERVER['HTTP_X_SLATE_CSRF'] ?? null;
    if (!slate_check_csrf(is_string($given) ? $given : null)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['ok' => false, 'error' => 'bad_csrf']);
        exit;
    }
    return $user;
}
