# Deploying The Slate

The tool lives at <https://creativemedia.church/filmmaking/slate/> on Namecheap
shared cPanel hosting (LiteSpeed, cPanel user `creaueyu`).

```
/home/creaueyu/
├── .filmmaking-config.php  database details — uploaded by hand, never in git
└── public_html/filmmaking/
    ├── .htaccess           cPanel Directory Privacy (see "Cutover" below)
    ├── index.html          public "Filmmaking Team" page
    ├── auth/               ← shared sign-in for EVERY tool here
    │   ├── login.php  signup.php  logout.php  reset.php
    │   ├── account.php  admin.php  index.php
    │   ├── auth.php  db.php  page.php  schema.sql   (includes, not reachable)
    │   └── .htaccess  config.sample.php
    ├── slate/              ← The Slate, deployed from this repo
    │   ├── index.php       the front door: requires a session, serves the app
    │   ├── app.html        the app itself — blocked from direct requests
    │   ├── list.php  save.php  load.php  delete.php  diag.php
    │   ├── lib.php
    │   └── .htaccess  .user.ini
    └── saved/              ← the team's storyboards, NEVER deployed
```

Accounts are shared across everything under `/filmmaking/`. `auth/` is the only
copy; the session cookie is scoped to the parent folder, so one sign-in covers
every tool. Both `auth/` and `slate/` are deployed by this repo's workflow, in
separate steps to separate folders.

`saved/` is deliberately a sibling of `slate/`, outside both deploy targets,
and `**/saved/**` is excluded from the workflow, so a deploy can never delete
the team's work.

## Automatic deploys

Pushing to `main` runs `.github/workflows/deploy.yml`, which does two FTPS
syncs: `auth/` in this repo to `filmmaking/auth/`, then the repo root to
`filmmaking/slate/`. `auth/**`, `src/**`, `tools/**`, `server/**` and the
markdown files are excluded from the second step — `auth/` because it has
already been deployed to its own folder, the rest because they are
development-side only.

## Accounts

The team signs in with their own accounts, stored in PostgreSQL. Signup is
self-service, gated by a shared invite code an admin creates. **This host has
no outbound mail**, so nothing depends on email: there are no verification
links, and a forgotten password is an admin generating a one-time code and
handing it over directly.

### 1. Create the database

cPanel → **PostgreSQL Databases** (or the wizard). Create a database and a user
and grant ALL privileges. cPanel prefixes both names with the account, so
`filmmaking_login` becomes `creaueyu_filmmaking_login`.

Name it for the team, not for The Slate: every tool under `/filmmaking/`
authenticates against this one database. Nothing in the code refers to the name
— it is only the `db_name` in the config — so use whatever cPanel let you
have. The live one is `creaueyu_filmmaking_login`, because `creaueyu_filmmaking`
was already taken.

The database and the user are named separately and need not match. Copy both
exactly as cPanel shows them.

Check `pdo_pgsql` is ticked under **Software → Select PHP Version →
Extensions**. A PostgreSQL server on the plan is no use if PHP cannot reach
it.

### 2. Upload the config

Copy `auth/config.sample.php` to `/home/creaueyu/.filmmaking-config.php` —
**above** `public_html`, so the file holding the database password is never
web-served. Fill in the database details and change `bootstrap_code` to
something only you know.

If `localhost` will not connect, try `127.0.0.1`, and check the port on the
PostgreSQL Databases page in case it is not 5432.

`db.php` also accepts `auth/config.php` if you must keep it inside the folder;
`.htaccess` blocks direct requests for it, and `.gitignore` keeps both names
out of git. Above the web root is better.

### 3. Create the first account

Visit `auth/signup.php`. While no accounts exist, the page asks for the
**setup code** — that is `bootstrap_code` — and the account it creates is the
admin. The code stops working the moment that account exists.

Then, as that admin, open `auth/admin.php` and create an invite code to share
with the team. From the admin page you can also:

- turn invite codes on and off, cap their uses, or give them an expiry
- generate a **reset code** for someone who is locked out (single use, expires
  after 48 hours, shown once — pass it to them in person or over chat)
- make someone an admin, or turn an account off, which signs it out immediately

### 4. Cutover: turn off cPanel Directory Privacy

Until you do this, people hit the browser's basic-auth prompt *and then* the
sign-in page. Do it in this order:

1. Deploy, then create your admin account and check you can sign in and save a
   storyboard. The basic-auth prompt is still in the way at this point; that is
   expected.
2. Upload `server/saved.htaccess` to `public_html/filmmaking/saved/.htaccess`
   (step 5 below). **Do this after the deploy, not before** — it closes the
   folder that the previously deployed client fetched storyboards from
   directly.
3. cPanel → **Directory Privacy** → `public_html/filmmaking` → untick password
   protection.

Keep the `filmmaking/index.html` page public as it always was. If anything goes
wrong, re-ticking Directory Privacy puts the old gate back immediately without
touching the app.

### 5. Close the saved folder

Upload `server/saved.htaccess` to `public_html/filmmaking/saved/.htaccess` by
hand, once. It cannot be deployed, because `saved/` is outside the deploy
target.

That folder is now closed to the web entirely. Storyboards used to be fetched
from it as static files, which was only safe because basic auth covered the
whole of `/filmmaking/`; reads now go through `slate/load.php` behind a session
check. PHP still reads the files from disk, which no directive there affects.

The file deliberately contains no `Require valid-user`: an access directive in
a child folder *overrides* the parent's, so getting clever there is how you
accidentally expose the library.

## Verifying an install

Sign in as an admin and open `slate/diag.php`. It reports the PHP version,
whether `pdo_pgsql` is present, the effective upload limits, and whether
`saved/.htaccess` is installed. It is readable by anyone only while no accounts
exist (so you can use it during setup) and by admins after that.

- **`postgres.pdo_pgsql` must be `true`.** A PostgreSQL server on the plan is
  no use if PHP cannot reach it. If it is `false`, enable the extension in
  cPanel → Select PHP Version.
- **`limits.post_max_size` should be around 64M.** If it reads `8M`,
  `.user.ini` has not taken effect: wait five minutes (`user_ini.cache_ttl`),
  then set it in cPanel → MultiPHP INI Editor instead.

Storyboards inline their reference images as base64 JPEGs, so a 30-shot board
is routinely several megabytes — that limit matters. The app reads the ceiling
from `list.php` before every save and refuses oversized boards with a clear
message, because a body over `post_max_size` is discarded by PHP before the
script runs and would otherwise fail silently.

## Adding another tool

Put it in its own folder under `filmmaking/` and start its front door with:

```php
require __DIR__ . '/../auth/auth.php';
$user = fm_require_login();   // bounces to the shared sign-in, comes back here
```

Then add one line to `auth/tools.php` so it appears in the nav everywhere:

```php
['label' => 'The Cage', 'path' => 'cage/', 'blurb' => 'Gear checkout'],
```

That is the whole integration. Anyone signed in to The Slate is already signed
in to it, and signing out of either signs out of both. `$user` gives you
`display_name`, `username` and `is_admin`.

### Putting the nav on a page of your own

Any page under `/filmmaking/` can carry the bar, including the public landing
page. It has to be PHP rather than plain HTML:

```php
<?php require __DIR__ . '/auth/auth.php'; require __DIR__ . '/auth/page.php'; ?>
<!DOCTYPE html>
<html><head>
  <link rel="stylesheet" href="/filmmaking/auth/nav.css">
</head>
<body>
<?php fm_nav('', 'dark'); ?>   <!-- drop the 'dark' on a light page -->
```

The bar takes its type and colour from custom properties, so it can be made to
match the page rather than imposing on it:

```css
.fmnav {
  --fm-display: 'Fraunces', serif;   /* the wordmark */
  --fm-body: 'Inter', sans-serif;    /* everything else */
  --fm-accent: #C98A3A;              /* hover and the current tool */
}
```

`nav.css` cannot know which webfonts a page loads, so a page using its own
type has to hand them over this way or the bar falls back to system fonts.

It adapts to who is looking: signed out it offers Sign in, signed in it shows
the name with Account, Team Admin and Sign out. The page itself stays public —
`fm_nav()` only reports a session, it does not require one.

If the tool renders its own pages with `auth/page.php`, it gets the shared
nav bar automatically. If it renders its own markup, call `fm_nav('cage/')`
where the header should go, or read `fm_tool_links()` and lay it out yourself —
The Slate does the latter, since its app replaces the whole document.

For a JSON endpoint use `fm_require_api_user()` (401 instead of a redirect) or
`fm_require_api_write()`, which also checks the `X-Slate-CSRF` header the
client echoes back from its first request.

Two things to keep right:

- **Never copy `auth/` into the new tool.** One copy is the point — a security
  fix should only need applying once.
- **Deploy it to its own folder**, not inside `auth/` or `slate/`. If it lives
  in this repo, add a third step to the workflow; if it has its own repo, give
  it an FTP deploy with `server-dir: ./<tool>/` (the FTP account is rooted at
  `filmmaking`).

### A tool on its own subdomain

The Cage lives at `cage.creativemedia.church`, not in a folder here. Three
things have to line up for one sign-in to cover it:

1. **`cookie_domain`** in the config, e.g. `.creativemedia.church`. Without it
   the session cookie is host-only and never reaches the subdomain. Setting it
   also forces the cookie path to `/`, since a tool at the root of its
   subdomain would never receive `/filmmaking/`.
2. **`auth_url`** set to the absolute URL of this folder, so the tool can link
   back to the shared sign-in.
3. **A `url` entry rather than `path`** in `auth/tools.php`. That also
   registers the origin as somewhere `?next=` may return to — the sign-in
   accepts a return to registered origins and nothing else, which is what
   stops it becoming an open redirect.

The subdomain's code needs to reach `auth/` and the database. On the same
cPanel account it can `require` these files by absolute path, e.g.
`/home/creaueyu/public_html/filmmaking/auth/auth.php`, and it will read the
same config from above the web root.

**Worth being deliberate about `cookie_domain`:** the session cookie is then
sent to every host under that domain, including unrelated or future ones. It is
the normal approach on a domain you fully control and only host your own things
on; it is a bad idea on one where anyone else can stand up a subdomain.

Every account can use every tool. If one ever needs restricting — say gear
checkout for leads only — that is an `app_access` table and one check in that
tool's front door, with nothing else changing.

## Housekeeping

Two folders grow quietly and are never served:

- `saved/.versions/` — the last 3 copies of each board, kept automatically when
  someone overwrites one. Older copies are pruned on each save.
- `saved/.trash/` — boards removed from the library, with their versions. These
  are never pruned, so empty this folder occasionally. **This is also where you
  recover a storyboard someone deleted by mistake**: move the newest
  `<id>.<timestamp>.json` back to `saved/<id>.json` and rename its
  `.meta.json` alongside it.

Both live inside `saved/`, so nothing here is touched by a deploy.

Expired sessions, old sign-in attempts and used reset codes are cleaned up
automatically on each successful sign-in.

## Editing the app

`app.html` is a self-extracting bundle: an unpacker, a gzip+base64 manifest of
libraries and fonts, and the actual app as one JSON-escaped string. Editing that
string directly makes every change a single unreadable 100 KB line in git, so
the readable source is `src/template.html`:

```sh
python3 tools/bundle.py unpack   # app.html -> src/template.html
# edit src/template.html
python3 tools/bundle.py pack     # src/template.html -> app.html
python3 tools/bundle.py check    # verify the two are in sync before committing
```

Commit both files. `pack` reproduces the bundler's exact encoding, so an
unpack/pack round-trip with no edits leaves `app.html` byte-identical.

## Requirements

PHP 7.4 or newer with `pdo_pgsql`, and PostgreSQL. On PHP 8.3+ uploads are
validated with `json_validate()`, which checks a 20 MB board without building
it in memory; older versions fall back to `json_decode()`. Passwords use
Argon2id where available, bcrypt otherwise.

The schema in `schema.sql` is applied automatically on the first request, inside
an advisory lock, so there is nothing to run by hand after a deploy.
