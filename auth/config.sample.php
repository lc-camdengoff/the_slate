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
 * account, so "filmmaking" becomes "creaueyu_filmmaking".
 */

return [
    // ---- Database ---------------------------------------------------------
    'db_host' => 'localhost',   // try 127.0.0.1 if localhost will not connect
    'db_port' => 5432,
    'db_name' => 'creaueyu_filmmaking',
    'db_user' => 'creaueyu_filmmaking',
    'db_pass' => 'put-the-password-you-set-in-cpanel-here',

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
