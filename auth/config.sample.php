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

    // The folder holding the tools. Worked out automatically from where auth/
    // sits; set it only if that guess is wrong.
    // 'base_path' => '/filmmaking/',

    // ---- Tools on their own subdomain -------------------------------------
    // By default the session cookie is host-only and scoped to the tools
    // folder, so it never leaves /filmmaking/ on this host. A tool on its own
    // subdomain (cage.creativemedia.church) would not receive it, and would
    // need its own separate sign-in.
    //
    // Setting cookie_domain shares one sign-in across every subdomain. Be
    // deliberate: the cookie is then sent to EVERY host under that domain,
    // including any unrelated or future one. Only do this on a domain you
    // fully control and only host your own things on.
    //
    // Path becomes "/" automatically when this is set, because a tool at the
    // root of its subdomain would never receive "/filmmaking/".
    // 'cookie_domain' => '.creativemedia.church',
    // 'cookie_path' => '/',

    // Absolute URL of the auth folder. Tools on other hosts need this to link
    // back here; a relative path would resolve against their own host.
    // 'auth_url' => 'https://creativemedia.church/filmmaking/auth/',

    // ---- The Cage ---------------------------------------------------------
    // Shared secret for auth/verify.php, which The Cage calls to check a
    // password because it is a Node app and cannot run PHP or read the
    // session cookie. Must match The Cage's SLATE_VERIFY_SECRET. Without it
    // verify.php answers 503 rather than becoming a public password oracle.
    //
    //   node -e "console.log(require('crypto').randomBytes(32).toString('hex'))"
    // 'cage_auth_secret' => '...',

    // Domains a signup address may be on, comma separated. Empty means any.
    // 'allowed_email_domains' => 'life.church',
];
