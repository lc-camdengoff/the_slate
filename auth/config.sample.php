<?php
/**
 * Filmmaking tools — shared configuration template.
 *
 * Copy this to a file named .filmmaking-config.php and upload it ABOVE the web
 * root, next to public_html:
 *
 *     /home/creaueyu/.filmmaking-config.php
 *
 * db.php looks there first, so the file with your database password never sits
 * in a web-served folder and is never in git. If you must keep it inside
 * auth/, name it config.php — .htaccess blocks direct requests for it — but
 * above the web root is better.
 *
 * One config serves every tool under /filmmaking/. Create the database and
 * user in cPanel → PostgreSQL Databases; cPanel prefixes both names with the
 * account, so "filmmaking_login" becomes "creaueyu_filmmaking_login".
 *
 * The database and the user are named separately and need not match — copy
 * both exactly as cPanel shows them. A user that does not match the database
 * is the usual reason for "Cannot reach the database".
 */

return [
    // ---- Database ---------------------------------------------------------
    // Note: "localhost" is a hostname to PostgreSQL, not the Unix socket — it
    // means TCP on 127.0.0.1, so swapping the two changes little. To use the
    // socket, set this to the directory holding it, e.g. /var/run/postgresql.
    // slate/diag.php tries each variant and reports which one connects.
    'db_host' => 'localhost',
    'db_port' => 5432,
    'db_name' => 'creaueyu_filmmaking_login',
    'db_user' => 'creaueyu_filmmaking_login',
    'db_pass' => 'put-the-password-you-set-in-cpanel-here',

    // Only if the server requires TLS ("require"). Leave out otherwise.
    // 'db_sslmode' => 'require',

    // ---- First account ----------------------------------------------------
    // Used only while the users table is empty: whoever signs up with this
    // code becomes the first admin. After that first signup it stops working
    // and all further accounts need an invite code from the admin page.
    // Change it from the default before deploying.
    'bootstrap_code' => 'change-me-before-you-deploy',

    // ---- Sessions ---------------------------------------------------------
    // How long a sign-in lasts, in days. Sliding: it extends on each request.
    'session_days' => 30,

    // The folder holding the tools, which is what the session cookie is scoped
    // to — the reason one sign-in covers all of them. Worked out automatically
    // from where auth/ sits; set it only if that guess is wrong.
    // 'base_path' => '/filmmaking/',
];
