<?php
/**
 * Filmmaking tools — shared accounts and sessions.
 *
 * One login across every tool under /filmmaking/. This folder is the only
 * copy: a tool includes ../auth/auth.php and calls fm_require_login(), and
 * the session cookie is scoped to the parent folder so it reaches all of them.
 *
 * Replaces the cPanel basic auth that used to guard /filmmaking/. Signup is
 * self-service, gated by a shared invite code; there is no outbound mail on
 * this host, so password resets are one-time codes an admin hands over rather
 * than emailed links.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/tools.php';

const FM_COOKIE = 'fm_session';
const FM_TOKEN_BYTES = 32;

// Throttles, per 15 minutes.
const FM_MAX_LOGIN_PER_IP = 20;
const FM_MAX_LOGIN_PER_USER = 8;
const FM_MAX_SIGNUP_PER_IP = 6;
const FM_THROTTLE_WINDOW = '15 minutes';

const FM_MIN_PASSWORD = 10;

/**
 * Turn any uncaught error into a bland response.
 *
 * Driver messages can carry the connection string, and a stack trace names
 * paths, so the detail goes to the error log and never to the browser.
 */
function fm_error_handler(string $as = 'json'): void
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

function fm_password_algo(): string
{
    return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
}

function fm_client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

function fm_is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

/**
 * URL path of the folder holding all the tools, e.g. "/filmmaking/".
 *
 * This is the parent of the auth folder, and it is what the session cookie is
 * scoped to — the reason one sign-in covers every tool. Set `base_path` in the
 * config to override; otherwise it is worked out from where this file sits
 * relative to the document root.
 */
function fm_base_path(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }

    $configured = (string) (fm_config()['base_path'] ?? '');
    if ($configured !== '') {
        return $base = '/' . trim($configured, '/') . '/';
    }

    $root = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $parent = dirname(__DIR__);
    if ($root !== false && $root !== '' && strpos($parent, $root) === 0) {
        $path = str_replace('\\', '/', substr($parent, strlen($root)));
        return $base = '/' . ltrim(rtrim($path, '/') . '/', '/');
    }

    // Last resort: one level up from the running script.
    $dir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    $up = rtrim(dirname($dir), '/');
    return $base = ($up === '' || $up === '.' ? '' : $up) . '/';
}

/**
 * Domain to scope the session cookie to.
 *
 * Empty means host-only: the cookie stays on the host that set it. Set
 * cookie_domain to something like ".creativemedia.church" to share one sign-in
 * across subdomains — needed when a tool lives on its own subdomain rather
 * than in a folder here.
 */
function fm_cookie_domain(): string
{
    return trim((string) (fm_config()['cookie_domain'] ?? ''));
}

/**
 * Path to scope the session cookie to.
 *
 * Normally the tools folder, so the cookie is not sent to unrelated parts of
 * the site. A cookie shared across subdomains has to be path "/" instead: a
 * tool at the root of its own subdomain would never receive "/filmmaking/".
 */
function fm_cookie_path(): string
{
    $configured = trim((string) (fm_config()['cookie_path'] ?? ''));
    if ($configured !== '') {
        return $configured;
    }
    return fm_cookie_domain() !== '' ? '/' : fm_base_path();
}

/**
 * URL of the shared auth folder, e.g. "/filmmaking/auth/".
 *
 * Set auth_url in the config to an absolute URL when tools live on other
 * hosts: they need to link here, and a relative path would resolve against
 * their own host.
 */
function fm_auth_url(string $page = ''): string
{
    $configured = trim((string) (fm_config()['auth_url'] ?? ''));
    if ($configured !== '') {
        return rtrim($configured, '/') . '/' . $page;
    }
    return fm_base_path() . 'auth/' . $page;
}

/**
 * Where to send someone back to after they sign in.
 *
 * Only same-site paths inside the tools folder are allowed. An unchecked
 * return URL on a login page is an open redirect, and a login page is the
 * worst place to have one — it is exactly what a phishing link would use.
 */
function fm_safe_next(?string $next): string
{
    $base = fm_base_path();
    if (!is_string($next) || $next === '') {
        return $base;
    }
    if (preg_match('/[\x00-\x1F\x7F]/', $next)) {
        return $base;
    }

    // A tool on its own host needs an absolute return URL. Allowed only when
    // its origin is one we registered in tools.php — never an origin taken
    // from the request, which is what would make this an open redirect.
    if (preg_match('#^https?://#i', $next)) {
        $parts = parse_url($next);
        if (empty($parts['scheme']) || empty($parts['host'])) {
            return $base;
        }
        $origin = strtolower($parts['scheme'] . '://' . $parts['host']
            . (isset($parts['port']) ? ':' . $parts['port'] : ''));
        if (!in_array($origin, fm_tool_origins(), true)) {
            return $base;
        }
        foreach (explode('/', (string) ($parts['path'] ?? '')) as $segment) {
            if (strtolower(rawurldecode($segment)) === '..') {
                return $base;
            }
        }
        return $next;
    }

    // Anything else with a scheme (javascript:, data:, ...) is refused.
    if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $next)) {
        return $base;
    }
    if (strpos($next, '//') === 0 || strpos($next, '\\') !== false) {
        return $base;
    }
    if (strpos($next, $base) !== 0) {
        return $base;
    }
    // "/filmmaking/../../x" passes the prefix test above and then normalises
    // to "/x" in the browser. It cannot leave this origin, but it does leave
    // the tools folder, which is the thing the prefix test exists to enforce.
    $path = parse_url($next, PHP_URL_PATH);
    if (!is_string($path)) {
        return $base;
    }
    foreach (explode('/', $path) as $segment) {
        if ($segment === '..' || $segment === '%2e%2e' || strtolower(rawurldecode($segment)) === '..') {
            return $base;
        }
    }
    return $next;
}

/** The current request path, for handing to fm_safe_next() later. */
function fm_current_url(): string
{
    return fm_safe_next((string) ($_SERVER['REQUEST_URI'] ?? ''));
}

/** Add ?next= to an auth page URL. */
function fm_auth_url_with_next(string $page, string $next): string
{
    return fm_auth_url($page) . '?next=' . rawurlencode($next);
}

// ---------------------------------------------------------------------------
// Throttling
// ---------------------------------------------------------------------------

/**
 * With emulated prepares off, PDO hands a PHP false to Postgres as an empty
 * string, which is not valid for a boolean column. Bind them by hand.
 */
function fm_bind_bool(PDOStatement $stmt, int $position, bool $value): void
{
    $stmt->bindValue($position, $value, PDO::PARAM_BOOL);
}

function fm_record_attempt(string $kind, string $subject, bool $ok): void
{
    $stmt = fm_db()->prepare(
        'INSERT INTO auth_attempts (kind, ip, subject, ok) VALUES (?, ?, ?, ?)'
    );
    $stmt->bindValue(1, $kind);
    $stmt->bindValue(2, fm_client_ip());
    $stmt->bindValue(3, substr($subject, 0, 120));
    fm_bind_bool($stmt, 4, $ok);
    $stmt->execute();
}

function fm_recent_failures(string $kind, string $column, string $value): int
{
    $sql = sprintf(
        'SELECT count(*) AS n FROM auth_attempts
          WHERE kind = ? AND %s = ? AND ok = false AND at > now() - interval %s',
        $column === 'ip' ? 'ip' : 'subject',
        "'" . FM_THROTTLE_WINDOW . "'"
    );
    $stmt = fm_db()->prepare($sql);
    $stmt->execute([$kind, $value]);
    return (int) ($stmt->fetch()['n'] ?? 0);
}

/** True when this client has burned through its allowance. */
function fm_throttled(string $kind, string $subject = ''): bool
{
    if ($kind === 'signup') {
        return fm_recent_failures('signup', 'ip', fm_client_ip()) >= FM_MAX_SIGNUP_PER_IP;
    }
    if (fm_recent_failures($kind, 'ip', fm_client_ip()) >= FM_MAX_LOGIN_PER_IP) {
        return true;
    }
    return $subject !== ''
        && fm_recent_failures($kind, 'subject', strtolower($subject)) >= FM_MAX_LOGIN_PER_USER;
}

// ---------------------------------------------------------------------------
// Sessions
// ---------------------------------------------------------------------------

function fm_session_days(): int
{
    return max(1, (int) (fm_config()['session_days'] ?? 30));
}

function fm_start_session(int $userId): void
{
    $token = bin2hex(random_bytes(FM_TOKEN_BYTES));
    $stmt = fm_db()->prepare(
        'INSERT INTO sessions (token_hash, user_id, expires_at, ip, user_agent)
         VALUES (?, ?, now() + (? || \' days\')::interval, ?, ?)'
    );
    $stmt->execute([
        hash('sha256', $token),
        $userId,
        (string) fm_session_days(),
        fm_client_ip(),
        substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300),
    ]);

    $cookie = [
        'expires' => time() + fm_session_days() * 86400,
        'path' => fm_cookie_path(),
        'secure' => fm_is_https(),
        'httponly' => true,
        // Lax keeps the cookie off cross-site POSTs, which is most of CSRF
        // handled before the token check below even runs. Subdomains of one
        // registrable domain are same-site, so this still allows the shared
        // sign-in to reach a tool on its own subdomain.
        'samesite' => 'Lax',
    ];
    if (fm_cookie_domain() !== '') {
        $cookie['domain'] = fm_cookie_domain();
    }
    setcookie(FM_COOKIE, $token, $cookie);
}

/** The signed-in user, or null. Extends the session as a side effect. */
/**
 * Resolve a session token to its user, extending the session.
 *
 * Separate from the cookie so a tool that cannot read a PHP session can ask
 * about one — The Cage is a Node app on its own subdomain and does exactly
 * that through verify.php. Only the hash of the token is ever compared, and
 * only a live session belonging to an enabled account resolves.
 */
function fm_user_by_token(string $token): ?array
{
    if ($token === '' || !preg_match('/^[0-9a-f]{64}$/', $token)) {
        return null;
    }

    $hash = hash('sha256', $token);
    $stmt = fm_db()->prepare(
        'SELECT u.* FROM sessions s
           JOIN users u ON u.id = s.user_id
          WHERE s.token_hash = ? AND s.expires_at > now() AND u.is_active'
    );
    $stmt->execute([$hash]);
    $found = $stmt->fetch();
    if (!$found) {
        return null;
    }

    $touch = fm_db()->prepare(
        'UPDATE sessions
            SET last_seen_at = now(), expires_at = now() + (? || \' days\')::interval
          WHERE token_hash = ?'
    );
    $touch->execute([(string) fm_session_days(), $hash]);

    return $found;
}

function fm_current_user(): ?array
{
    static $user = null;
    static $looked = false;
    if ($looked) {
        return $user;
    }
    $looked = true;

    $user = fm_user_by_token((string) ($_COOKIE[FM_COOKIE] ?? ''));
    return $user;
}

function fm_end_session(): void
{
    $token = (string) ($_COOKIE[FM_COOKIE] ?? '');
    if ($token !== '' && preg_match('/^[0-9a-f]{64}$/', $token)) {
        $stmt = fm_db()->prepare('DELETE FROM sessions WHERE token_hash = ?');
        $stmt->execute([hash('sha256', $token)]);
    }
    // Must match how it was set, or the browser keeps the original.
    $cookie = [
        'expires' => time() - 3600,
        'path' => fm_cookie_path(),
        'secure' => fm_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    if (fm_cookie_domain() !== '') {
        $cookie['domain'] = fm_cookie_domain();
    }
    setcookie(FM_COOKIE, '', $cookie);
}

/** Drop every session for a user — used after a password change. */
function fm_end_all_sessions(int $userId, bool $keepCurrent = false): void
{
    $token = (string) ($_COOKIE[FM_COOKIE] ?? '');
    if ($keepCurrent && preg_match('/^[0-9a-f]{64}$/', $token)) {
        $stmt = fm_db()->prepare('DELETE FROM sessions WHERE user_id = ? AND token_hash <> ?');
        $stmt->execute([$userId, hash('sha256', $token)]);
        return;
    }
    $stmt = fm_db()->prepare('DELETE FROM sessions WHERE user_id = ?');
    $stmt->execute([$userId]);
}

/** Opportunistic cleanup; cheap enough to run on login. */
function fm_prune(): void
{
    $db = fm_db();
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
function fm_csrf_token(): string
{
    $token = (string) ($_COOKIE[FM_COOKIE] ?? '');
    if ($token === '') {
        return '';
    }
    return hash_hmac('sha256', 'csrf', $token);
}

function fm_check_csrf(?string $given): bool
{
    $expected = fm_csrf_token();
    return $expected !== '' && is_string($given) && hash_equals($expected, $given);
}

// ---------------------------------------------------------------------------
// Accounts
// ---------------------------------------------------------------------------

function fm_find_user(string $username): ?array
{
    $stmt = fm_db()->prepare('SELECT * FROM users WHERE username_ci = ?');
    $stmt->execute([strtolower(trim($username))]);
    return $stmt->fetch() ?: null;
}

function fm_user_count(): int
{
    return (int) (fm_db()->query('SELECT count(*) AS n FROM users')->fetch()['n'] ?? 0);
}

function fm_valid_username(string $username): bool
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
function fm_redeem_invite(string $code): bool
{
    $stmt = fm_db()->prepare(
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
 * Hand back a use reserved by fm_redeem_invite() when the signup that
 * claimed it then failed — otherwise a typo'd username would quietly burn a
 * use of a limited code.
 */
function fm_release_invite(string $code): void
{
    $stmt = fm_db()->prepare(
        'UPDATE invite_codes SET uses = uses - 1 WHERE code = ? AND uses > 0'
    );
    $stmt->execute([$code]);
}

/**
 * Create an account.
 *
 * @return array{0:bool,1:string} [ok, error key]
 */
function fm_create_user(string $username, string $displayName, string $password, bool $isAdmin): array
{
    if (!fm_valid_username($username)) {
        return [false, 'bad_username'];
    }
    if (strlen($password) < FM_MIN_PASSWORD) {
        return [false, 'weak_password'];
    }
    $displayName = trim($displayName) !== '' ? trim($displayName) : $username;

    try {
        $stmt = fm_db()->prepare(
            'INSERT INTO users (username, username_ci, display_name, password_hash, is_admin)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->bindValue(1, trim($username));
        $stmt->bindValue(2, strtolower(trim($username)));
        $stmt->bindValue(3, substr($displayName, 0, 80));
        $stmt->bindValue(4, password_hash($password, fm_password_algo()));
        fm_bind_bool($stmt, 5, $isAdmin);
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
function fm_attempt_login(string $username, string $password): array
{
    if (fm_throttled('login', $username)) {
        fm_record_attempt('login', $username, false);
        return [false, 'throttled'];
    }

    $user = fm_find_user($username);
    // Hash even when the user is missing, so a bad username and a bad password
    // take about the same time.
    $hash = $user['password_hash'] ?? '$2y$10$usernamedoesnotexistpaddingpaddingpaddingpaddingpaddingpad';
    $ok = password_verify($password, $hash);

    if (!$user || !$ok || !$user['is_active']) {
        fm_record_attempt('login', $username, false);
        return [false, $user && $ok && !$user['is_active'] ? 'disabled' : 'bad_credentials'];
    }

    if (password_needs_rehash($hash, fm_password_algo())) {
        $stmt = fm_db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([password_hash($password, fm_password_algo()), $user['id']]);
    }

    fm_start_session((int) $user['id']);
    fm_db()->prepare('UPDATE users SET last_login_at = now() WHERE id = ?')
        ->execute([$user['id']]);
    fm_record_attempt('login', $username, true);
    fm_prune();
    return [true, ''];
}

function fm_set_password(int $userId, string $password): bool
{
    if (strlen($password) < FM_MIN_PASSWORD) {
        return false;
    }
    $stmt = fm_db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
    $stmt->execute([password_hash($password, fm_password_algo()), $userId]);
    return true;
}

// ---------------------------------------------------------------------------
// Gatekeeping for the JSON endpoints
// ---------------------------------------------------------------------------

/**
 * Require a signed-in user for an API request, answering 401 as JSON when
 * there is none. Returns the user row.
 */
/**
 * Gate a whole page on being signed in.
 *
 * This is what a tool calls. Anyone without a session is sent to the shared
 * sign-in page and comes back here afterwards.
 */
function fm_require_login(): array
{
    $user = fm_current_user();
    if ($user === null) {
        header('Location: ' . fm_auth_url_with_next('login.php', fm_current_url()));
        exit;
    }
    return $user;
}

function fm_require_api_user(): array
{
    $user = fm_current_user();
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
function fm_require_api_write(): array
{
    $user = fm_require_api_user();
    $given = $_SERVER['HTTP_X_SLATE_CSRF'] ?? null;
    if (!fm_check_csrf(is_string($given) ? $given : null)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['ok' => false, 'error' => 'bad_csrf']);
        exit;
    }
    return $user;
}
