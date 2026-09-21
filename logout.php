<?php
/**
 * The Slate — sign out.
 *
 * POST only, with the CSRF token, so a stray link or an image tag on another
 * site cannot sign people out.
 */

declare(strict_types=1);

require __DIR__ . '/auth.php';

slate_install_error_handler('html');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && slate_check_csrf($_POST['csrf'] ?? null)) {
    slate_end_session();
}

header('Location: login.php');
