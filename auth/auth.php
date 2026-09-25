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

// How long a setup code for a new or imported account lasts. Longer than a
// reset code: it is handed out in a batch and people get to it when they can.
const FM_SETUP_CODE_HOURS = 14 * 24;
const FM_RESET_CODE_HOURS = 48;

// Verified against when there is no real hash to check, so a missing account
// costs the same time as a wrong password. A real hash of a random password
// nobody kept — a malformed one would fail instantly and give the game away —
// and never accepted even if it somehow matched.
const FM_DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$NmFrNlFVc1pOUUZaSURiaA$deNt8RkLzz2j5ZENsqe2AGQ9nZqtf1C1SWNJW3Ku+0I';

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
    static $domain = null;
    if ($domain !== null) {
        return $domain;
    }
    $configured = strtolower(trim((string) (fm_config()['cookie_domain'] ?? '')));
    if ($configured === '') {
        return $domain = '';
    }

    /* A browser silently drops a cookie whose domain does not cover the host
       that set it, and a dropped session cookie looks exactly like a sign-in
       that loops back to the page it came from. So a value with a scheme, a
       port, or a domain this host is not under is ignored and logged, and the
       cookie stays host-only — one sign-in per host beats nobody signing in. */
    $host = strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
    $bare = ltrim($configured, '.');
    $covers = $host === $bare || substr($host, -strlen('.' . $bare)) === '.' . $bare;
    if (!preg_match('/^\.?[a-z0-9-]+(\.[a-z0-9-]+)+$/', $configured) || ($host !== '' && !$covers)) {
        error_log("slate: ignoring cookie_domain '$configured' — it does not cover host '$host'");
        return $domain = '';
    }
    return $domain = $configured;
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

    fm_set_session_cookie($token);
}

/**
 * Every path/domain a session cookie could have been set under.
 *
 * A cookie is identified by name, domain AND path, so changing cookie_domain
 * or cookie_path does not replace the old cookie — it adds a second one with
 * the same name. The browser then sends both, the more specific path first,
 * and $_COOKIE keeps only the first. A stale "/filmmaking/" cookie therefore
 * hides a fresh "/" one, and signing in appears to do nothing. Knowing every
 * variant is what lets the stale ones be expired.
 *
 * @return list<array{path: string, domain: string}>
 */
function fm_cookie_variants(): array
{
    $domains = array_unique(['', fm_cookie_domain()]);
    $paths = array_unique([fm_cookie_path(), fm_base_path(), '/']);
    $variants = [];
    foreach ($domains as $domain) {
        foreach ($paths as $path) {
            $variants[] = ['path' => $path, 'domain' => $domain];
        }
    }
    return $variants;
}

/**
 * Set the session cookie, expiring any same-named cookie left under another
 * path or domain first. Pass '' to clear every variant.
 */
function fm_set_session_cookie(string $token): void
{
    $base = [
        'secure' => fm_is_https(),
        'httponly' => true,
        // Lax keeps the cookie off cross-site POSTs, which is most of CSRF
        // handled before the token check below even runs. Subdomains of one
        // registrable domain are same-site, so this still allows the shared
        // sign-in to reach a tool on its own subdomain.
        'samesite' => 'Lax',
    ];
    $current = ['path' => fm_cookie_path(), 'domain' => fm_cookie_domain()];

    foreach (fm_cookie_variants() as $variant) {
        if ($token !== '' && $variant === $current) {
            continue; // set below; expiring it here would race the real one
        }
        $cookie = $base + ['expires' => time() - 3600, 'path' => $variant['path']];
        if ($variant['domain'] !== '') {
            $cookie['domain'] = $variant['domain'];
        }
        setcookie(FM_COOKIE, '', $cookie);
    }

    if ($token === '') {
        return;
    }
    $cookie = $base + ['expires' => time() + fm_session_days() * 86400, 'path' => $current['path']];
    if ($current['domain'] !== '') {
        $cookie['domain'] = $current['domain'];
    }
    setcookie(FM_COOKIE, $token, $cookie);
}

/**
 * Every session token the browser sent, in the order it sent them.
 *
 * Read from the raw Cookie header because $_COOKIE keeps only the first of
 * several same-named cookies — the exact situation fm_cookie_variants()
 * describes.
 *
 * @return list<string>
 */
function fm_cookie_tokens(): array
{
    $tokens = [];
    foreach (explode(';', (string) ($_SERVER['HTTP_COOKIE'] ?? '')) as $pair) {
        $parts = explode('=', $pair, 2);
        if (count($parts) === 2 && trim($parts[0]) === FM_COOKIE) {
            $value = trim(urldecode($parts[1]));
            if (preg_match('/^[0-9a-f]{64}$/', $value)) {
                $tokens[] = $value;
            }
        }
    }
    // Fallback for a SAPI that does not expose the raw header.
    $single = (string) ($_COOKIE[FM_COOKIE] ?? '');
    if ($tokens === [] && preg_match('/^[0-9a-f]{64}$/', $single)) {
        $tokens[] = $single;
    }
    return array_values(array_unique($tokens));
}

/**
 * Resolve a session token to its user, extending the session.
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

/**
 * The token of the live session this request belongs to, or ''.
 *
 * The first of the browser's tokens that resolves, not simply the first one
 * sent — see fm_cookie_variants() for why those can differ.
 */
function fm_session_token(): string
{
    fm_current_user();
    return fm_session_state()['token'];
}

/** @return array{looked: bool, token: string, user: ?array} */
function &fm_session_state(): array
{
    static $state = ['looked' => false, 'token' => '', 'user' => null];
    return $state;
}

/** The signed-in user, or null. Extends the session as a side effect. */
function fm_current_user(): ?array
{
    $state = &fm_session_state();
    if ($state['looked']) {
        return $state['user'];
    }
    $state['looked'] = true;

    $tokens = fm_cookie_tokens();
    foreach ($tokens as $token) {
        $user = fm_user_by_token($token);
        if ($user) {
            $state['token'] = $token;
            $state['user'] = $user;
            break;
        }
    }

    /* Re-issue the cookie under the configured path and domain, expiring any
       other copies. This keeps the browser's expiry in step with the session's
       sliding one, and it is what moves someone signed in before cookie_domain
       was set onto the shared cookie — without it they would have to sign out
       and back in before The Cage could see them. */
    if ($state['user'] !== null && !headers_sent()) {
        fm_set_session_cookie($state['token']);
    } elseif ($state['user'] === null && $tokens !== [] && !headers_sent()) {
        fm_set_session_cookie(''); // only dead tokens; stop sending them
    }
    return $state['user'];
}

function fm_end_session(): void
{
    $token = fm_session_token();
    if ($token !== '') {
        $stmt = fm_db()->prepare('DELETE FROM sessions WHERE token_hash = ?');
        $stmt->execute([hash('sha256', $token)]);
    }
    // Every variant, or a leftover copy would keep this browser signed in.
    fm_set_session_cookie('');

    $state = &fm_session_state();
    $state = ['looked' => true, 'token' => '', 'user' => null];
}

/** Drop every session for a user — used after a password change. */
function fm_end_all_sessions(int $userId, bool $keepCurrent = false): void
{
    $token = fm_session_token();
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
    $token = fm_session_token();
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

/**
 * Find an account by username, or by email address if what was typed has an
 * @ in it — people will type the whole address into a username box, and
 * since the username is made from the address there is no reason to refuse.
 */
function fm_find_user(string $username): ?array
{
    $username = strtolower(trim($username));
    if (strpos($username, '@') !== false) {
        $stmt = fm_db()->prepare('SELECT * FROM users WHERE lower(email) = ?');
    } else {
        $stmt = fm_db()->prepare('SELECT * FROM users WHERE username_ci = ?');
    }
    $stmt->execute([$username]);
    return $stmt->fetch() ?: null;
}

/**
 * What a sign-in attempt is throttled and logged under. An address and the
 * username made from it are the same account, so they share one allowance —
 * otherwise switching between the two would double the guesses allowed.
 */
function fm_login_subject(string $typed): string
{
    $typed = strtolower(trim($typed));
    return strpos($typed, '@') !== false ? (string) strstr($typed, '@', true) : $typed;
}

function fm_user_count(): int
{
    return (int) (fm_db()->query('SELECT count(*) AS n FROM users')->fetch()['n'] ?? 0);
}

/**
 * Usernames are the part of the email before the @ (first.last), so this
 * accepts what a real address can start with. Older accounts that picked
 * their own name at signup also pass.
 */
function fm_valid_username(string $username): bool
{
    return preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]{0,63}$/', $username) === 1;
}

/**
 * The username an email address gives: everything before the @, lowercased.
 * camden.goff@life.church → camden.goff. '' if the address can't make one.
 */
function fm_username_for_email(string $email): string
{
    $email = strtolower(trim($email));
    $at = strrpos($email, '@');
    if ($at === false || $at === 0) {
        return '';
    }
    $local = substr($email, 0, $at);
    return fm_valid_username($local) ? $local : '';
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
    $typed = $username;
    $username = fm_login_subject($typed);
    if (fm_throttled('login', $username)) {
        fm_record_attempt('login', $username, false);
        return [false, 'throttled'];
    }

    $user = fm_find_user($typed);
    // Hash even when the user is missing, so a bad username and a bad password
    // take about the same time. An account still waiting for its setup code
    // gets the same treatment, so it cannot be told apart from a wrong
    // password either.
    $hash = $user && fm_has_password($user) ? (string) $user['password_hash'] : FM_DUMMY_HASH;
    $ok = password_verify($password, $hash) && $hash !== FM_DUMMY_HASH;

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

/**
 * False for an account an admin or an import made, until its owner redeems
 * the setup code they were given and picks a password.
 */
function fm_has_password(array $user): bool
{
    return (string) ($user['password_hash'] ?? '') !== '';
}

/**
 * Issue a one-time code that lets someone set this account's password.
 *
 * The same thing serves as a reset code for someone who forgot theirs and a
 * setup code for someone whose account was imported. Only its hash is kept,
 * so it can be shown once and never again.
 */
function fm_issue_code(int $userId, int $createdBy, int $hours): string
{
    $code = strtolower(bin2hex(random_bytes(4)) . '-' . bin2hex(random_bytes(4)));
    // One live code per person: issuing a new one retires the last, so a code
    // sent to the wrong place can be cancelled by sending another.
    fm_db()->prepare('UPDATE password_resets SET used_at = now() WHERE user_id = ? AND used_at IS NULL')
        ->execute([$userId]);
    $stmt = fm_db()->prepare(
        'INSERT INTO password_resets (user_id, code_hash, expires_at, created_by)
         VALUES (?, ?, now() + (?::text || \' hours\')::interval, ?)'
    );
    $stmt->execute([$userId, hash('sha256', $code), (string) max(1, $hours), $createdBy ?: null]);
    return $code;
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
function fm_require_login(string $tool = ''): array
{
    $user = fm_current_user();
    if ($user === null) {
        header('Location: ' . fm_auth_url_with_next('login.php', fm_current_url()));
        exit;
    }
    if ($tool !== '' && !fm_can_use($user, $tool)) {
        require_once __DIR__ . '/page.php';
        http_response_code(403);
        fm_page_head('No access');
        echo '<div class="card"><div class="eyebrow">Filmmaking tools</div>'
            . '<h1>No access</h1><p class="note" style="margin-top:0">Your account '
            . 'is not set up to use ' . fm_h((string) (fm_tool($tool)['label'] ?? 'this tool'))
            . '. Ask an admin if you need it.</p></div>';
        fm_page_foot();
        exit;
    }
    return $user;
}

/**
 * @param string $tool when given, also require access to this tool (key from
 *   tools.php), answering 403 no_access without it
 */
function fm_require_api_user(string $tool = ''): array
{
    $user = fm_current_user();
    if ($user === null || ($tool !== '' && !fm_can_use($user, $tool))) {
        http_response_code($user === null ? 401 : 403);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['ok' => false, 'error' => $user === null ? 'not_signed_in' : 'no_access']);
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
function fm_require_api_write(string $tool = ''): array
{
    $user = fm_require_api_user($tool);
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
