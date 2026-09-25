<?php
/**
 * Password check for The Cage.
 *
 * Mirrored from the-cage's repository, where it is one half of a contract
 * whose other half is src/slate-auth.js. Two halves of one contract kept in
 * two places drift without anyone noticing, so when one changes, copy it
 * across.
 *
 * ---------------------------------------------------------------- what it is
 *
 * The Cage is a Node app on its own subdomain, so it cannot call PHP functions
 * and cannot verify a session itself. It asks this endpoint instead, in one of
 * two ways:
 *
 *   {"session": "<fm_session cookie value>"}   — who is this, if anyone?
 *   {"username": "...", "password": "..."}     — is this person real?
 *
 * The session form is the one to prefer. The cookie is scoped to the whole
 * domain, so a browser signed in to the Slate sends it to The Cage as well:
 * asking about it means nobody types a password twice, and signing out of
 * either tool ends both. The password form stays for a direct sign-in at The
 * Cage, and for when there is no cookie to ask about.
 *
 * The point is that auth.php stays the only thing that ever checks a password.
 * The alternative — giving The Cage database credentials and reimplementing
 * argon2 verification in Node — means two copies of the logic, two rate
 * limiters, and a silent breakage the day PHP's password_hash() defaults move.
 *
 * No session is started. The Cage mints its own; this only answers a question.
 *
 * The answer carries `role`, the person's role in The Cage as set on the Team
 * admin page (member, manager or admin; see tools.php), and `tools`, every
 * tool they can use. Someone whose Cage access is "No access" gets 403
 * no_access from either form, even with the right password.
 *
 * -------------------------------------------------------------- who may call
 *
 * A shared secret in X-Cage-Auth, compared in constant time. Without it this
 * would be an unauthenticated password oracle on the public internet, which is
 * a worse thing to own than whatever it was meant to save.
 *
 * Set cage_auth_secret in the Slate's config to the same value as The Cage's
 * SLATE_VERIFY_SECRET. Generate it with:
 *
 *   node -e "console.log(require('crypto').randomBytes(32).toString('hex'))"
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

fm_error_handler('json');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

/** Answer and stop. Never says more than the caller needs. */
function cage_reply(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

/** The Cage's key in tools.php — whose role this endpoint reports. */
const CAGE_TOOL = 'cage';

/**
 * Answer with who this is, or refuse someone the admin page has not given
 * access to The Cage.
 *
 * The password hash is never in this response, and nor is anything else the
 * caller did not ask about — hence naming the fields rather than returning
 * the row. `role` is this person's role in The Cage, from the admin page;
 * `tools` is every tool they can use, for a nav that matches the Slate's.
 */
function cage_reply_user(array $user): void
{
    $role = fm_tool_role($user, CAGE_TOOL);
    if ($role === FM_NO_ACCESS) {
        cage_reply(403, ['ok' => false, 'error' => 'no_access']);
    }
    $tools = array_filter(fm_tool_roles((int) $user['id']), static fn($r) => $r !== FM_NO_ACCESS);
    $text = static fn($v) => $v === null || $v === '' ? null : (string) $v;
    cage_reply(200, [
        'ok' => true,
        // Stable for the life of the account. The username can change (it
        // follows the email), so this is what The Cage should key people on.
        'id' => (int) $user['id'],
        'username' => (string) $user['username'],
        'display_name' => (string) ($user['display_name'] ?? ''),
        'email' => $text($user['email'] ?? null),
        'phone' => $text($user['phone'] ?? null),
        'department' => $text($user['department'] ?? null),
        'is_admin' => (bool) $user['is_admin'],
        'role' => $role,
        'tools' => (object) $tools,
    ]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    cage_reply(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

/* The secret lives in the Slate's own config alongside the database
   credentials, rather than in this file, so rotating it is a config edit and
   this file stays copy-pasteable.
 *
 * Three places are checked because which one works depends on how PHP is
 * running here. getenv() misses variables set by Apache's SetEnv under
 * PHP-FPM, where they arrive in $_SERVER instead — and finding that out by
 * way of a 503 is a poor use of an afternoon. The config array is still the
 * tidiest home if this install has one. */
$expected = (string) (
    fm_config()['cage_auth_secret']
    ?? (getenv('FM_CAGE_AUTH_SECRET') ?: null)
    ?? ($_SERVER['FM_CAGE_AUTH_SECRET'] ?? '')
);
$given = (string) ($_SERVER['HTTP_X_CAGE_AUTH'] ?? '');

if ($expected === '') {
    error_log('slate: verify.php called but no cage_auth_secret is configured');
    cage_reply(503, ['ok' => false, 'error' => 'not_configured']);
}
if ($given === '' || !hash_equals($expected, $given)) {
    cage_reply(401, ['ok' => false, 'error' => 'bad_secret']);
}

$raw = file_get_contents('php://input');
$body = json_decode((string) $raw, true);
if (!is_array($body)) {
    cage_reply(400, ['ok' => false, 'error' => 'bad_request']);
}

/* ------------------------------------------------------------- session form
 *
 * Answering "who holds this token" is not a guessing game the way a password
 * is — the token is 64 hex characters and either matches a live row or does
 * not — so this path is neither throttled nor recorded. Logging every page
 * view of The Cage into auth_attempts would bury the failed sign-ins that
 * table exists to show. */
$sessionToken = trim((string) ($body['session'] ?? ''));
if ($sessionToken !== '') {
    $user = fm_user_by_token($sessionToken);
    if (!$user) {
        // Expired, unknown, or the account has since been turned off.
        cage_reply(401, ['ok' => false, 'error' => 'no_session']);
    }
    cage_reply_user($user);
}

$username = trim((string) ($body['username'] ?? ''));
$password = (string) ($body['password'] ?? '');

if ($username === '' || $password === '') {
    cage_reply(400, ['ok' => false, 'error' => 'missing_credentials']);
}

/* Throttling is the Slate's, not a second one — so attempts from The Cage and
   attempts at the Slate's own login page count against the same allowance.
   Somebody guessing passwords does not get a fresh budget by switching which
   door they knock on. */
$typed = $username;
$username = fm_login_subject($typed);
if (fm_throttled('login', $username)) {
    fm_record_attempt('login', $username, false);
    cage_reply(429, ['ok' => false, 'error' => 'throttled']);
}

$user = fm_find_user($typed);

/* Hash even when the user is missing, so a bad username and a bad password
   take about the same time. Mirrors fm_attempt_login() deliberately: this
   endpoint is that function without the session, and the timing property is
   part of what it does. */
$hash = $user && fm_has_password($user) ? (string) $user['password_hash'] : FM_DUMMY_HASH;
$ok = password_verify($password, $hash) && $hash !== FM_DUMMY_HASH;

if (!$user || !$ok) {
    fm_record_attempt('login', $username, false);
    cage_reply(401, ['ok' => false, 'error' => 'bad_credentials']);
}

if (!$user['is_active']) {
    fm_record_attempt('login', $username, false);
    cage_reply(403, ['ok' => false, 'error' => 'disabled']);
}

/* Keep hashes current even when someone only ever signs in via The Cage —
   otherwise those accounts quietly miss the rehash that fm_attempt_login()
   does, and stay on an older algorithm indefinitely. */
if (password_needs_rehash($hash, fm_password_algo())) {
    $stmt = fm_db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
    $stmt->execute([password_hash($password, fm_password_algo()), $user['id']]);
}

fm_db()->prepare('UPDATE users SET last_login_at = now() WHERE id = ?')->execute([$user['id']]);
fm_record_attempt('login', $username, true);

cage_reply_user($user);
