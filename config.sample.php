<?php
/**
 * The Slate — configuration template.
 *
 * Copy this to a file named .slate-config.php and upload it ABOVE the web root,
 * next to public_html:
 *
 *     /home/creaueyu/.slate-config.php
 *
 * db.php looks there first, so the file with your database password never sits
 * in a web-served folder and is never in git. If you must keep it inside
 * slate/, name it config.php — .htaccess blocks direct requests for it — but
 * above the web root is better.
 *
 * Create the database and user in cPanel → PostgreSQL Databases. cPanel
 * prefixes both names with the account, so "slate" becomes "creaueyu_slate".
 */

return [
    // ---- Database ---------------------------------------------------------
    'db_host' => 'localhost',
    'db_port' => 5432,
    'db_name' => 'creaueyu_slate',
    'db_user' => 'creaueyu_slate',
    'db_pass' => 'put-the-password-you-set-in-cpanel-here',

    // ---- First account ----------------------------------------------------
    // Used only while the users table is empty: whoever signs up with this
    // code becomes the first admin. After that first signup it stops working
    // and all further accounts need an invite code from the admin page.
    // Change it from the default before deploying.
    'bootstrap_code' => 'change-me-before-you-deploy',

    // ---- Sessions ---------------------------------------------------------
    // How long a login lasts, in days. Sliding: it extends on each request.
    'session_days' => 30,
];
