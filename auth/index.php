<?php
/**
 * Filmmaking tools — /filmmaking/auth/
 *
 * Signed in, send people to their account page; otherwise to sign in. The
 * .htaccess here sets DirectoryIndex too, but not every host honours it, and
 * a bare folder URL should never show a listing or a 404.
 */

declare(strict_types=1);

require __DIR__ . '/auth.php';

fm_error_handler('html');

$next = fm_safe_next($_REQUEST['next'] ?? null);

header('Location: ' . (fm_current_user() !== null
    ? fm_auth_url_with_next('account.php', $next)
    : fm_auth_url_with_next('login.php', $next)));
