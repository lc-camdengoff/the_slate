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
    │   ├── account.php  admin.php  person.php  import.php  index.php
    │   ├── verify.php      The Cage asks who a session belongs to
    │   ├── auth.php  db.php  page.php  people.php  tools.php  email.php
    │   │   schema.sql      (includes, not reachable)
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
- click anyone in the **Team** list to edit their details (name, email, phone,
  department, admin-only notes), set what they can use in each tool, make them
  an admin, turn the account off (signs it out everywhere at once, keeps
  everything), or delete it
- generate a **reset code** for someone who is locked out (single use, expires
  after 48 hours, shown once — pass it to them in person or over chat)
- **Add person** to make an account for someone, or **Import from CSV** to
  bring a whole team over (below)

**Usernames come from the email.** A username is always the part of the email
before the @: `camden.goff@life.church` signs in as `camden.goff`, and the
full address works in the username box too. Signup has no username box, and
the import and **Add person** both follow the same rule. Changing someone's
email on their page renames them. Their password is kept, their devices stay
signed in, and their storyboards follow them, because each tool with a
`hooks` entry in `tools.php` is told about the rename.

Accounts made before this rule appear under **Usernames to update** on Team
admin, with the name each will get. One button renames them all. Tell each
person their new username. An account with no email keeps its old username
until one is added.

**Deleting someone** is at the bottom of their page, and you have to type their
username to confirm. You can't delete your own account. Their sessions, codes and
access go with it. In the Slate, their private storyboards move to
`saved/.trash` (still recoverable by moving the files back), and the boards
they shared with the team stay in the library, credited to them. The username
on those shared boards becomes `(deleted) first.last`, so nobody given the
same username later inherits them. To keep everything, turn the account off instead.

#### Importing from Cheqroom (or any spreadsheet)

Export the people as CSV, then **Team admin → Import from CSV**. Nothing is
saved until the last step:

1. **Columns.** Each field is matched to a column by its header name; fix any
   guess that's wrong. Email is required — it's how people already here are
   matched, and The Cage needs it for reminders.
2. **Access.** Pick what new people get in each tool, and what each role value
   in the file becomes in The Cage. The suggestions are deliberately stingy:
   only "Admin" becomes Admin, so raise anything else by hand. Nobody becomes
   an admin of the Team admin page through an import.
3. **Preview.** Every row shows New, Update, Here or Skip, and why. Untick
   anyone to leave them out. Rows are skipped for no email, an address that
   isn't valid, or a repeat of an earlier row. Any domain is accepted here,
   and on Add person and each person's page: `allowed_email_domains` in the
   config applies only to people signing themselves up.
   A row is also skipped if the username its email makes (the part before the
   @) already belongs to a different account.
4. **Import.** Each new person gets a **setup code**, shown once, with a
   tab-separated list to paste into a spreadsheet. They open
   `auth/reset.php?setup=1&u=<username>` (the link is in the list), enter the
   code and choose a password, and are signed straight in. Setup codes last
   14 days. A person's page can issue a new one, which cancels the old.

No passwords come across. An imported account can't sign in until its setup
code is used, and nobody but its owner ever knows its password.

**One code for everyone instead.** When you create an invite code, tick
*Also works for people already added*. Anyone whose account is waiting can
then enter that shared code and their email on the sign-up page, choose a
password, and get the account with the access you already gave it. Their
personal setup code stops working. You can switch this on or off for each
code in the list. It's easier to hand out, but anyone holding the code who
knows a waiting teammate's email could claim that account, so turn it off
(or give the code an expiry) once everyone is in. Accounts that already have
a password can never be claimed this way.

For someone who already has an account, the import only fills in phone,
department and notes where theirs are blank. It changes their access only if
you tick "Also set their access from this file".

#### Access to each tool

Each tool in `auth/tools.php` lists its own roles, plus a default for anyone
not given one. The Slate has Member; The Cage has Member, Gear manager and
Admin. Setting a tool to **No access** removes it from that person's nav bar
and refuses them at the door: the Slate shows a "No access" page, and
`verify.php` tells The Cage `no_access`.

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

Then add one entry to `auth/tools.php` so it appears in the nav everywhere and
gets a row on each person's admin page:

```php
['key' => 'edit', 'label' => 'The Edit Bay', 'path' => 'edit/', 'blurb' => 'Post',
 'roles' => ['member' => 'Member'], 'default_role' => 'member'],
```

and gate its front door on that key: `fm_require_login('edit')`. That is the
whole integration. Anyone signed in to The Slate is already signed in to it,
and signing out of either signs out of both. `$user` gives you
`display_name`, `username` and `is_admin`, and `fm_tool_role($user, 'edit')`
gives their role there.

Never change a tool's `key` once people have roles in it: roles are stored
against it.

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

### The Cage

The Cage is a Node app on its own subdomain, so it can neither run PHP nor
read the session cookie. It calls `auth/verify.php` instead: username and
password in, "is this person real" out. No session is started — The Cage mints
its own.

That keeps `auth.php` the only thing that ever checks a password. The
alternative, giving The Cage database credentials and reimplementing argon2 in
Node, means two copies of the logic, two rate limiters, and a silent breakage
the day PHP's `password_hash()` defaults move.

It takes a POST with the shared secret in `X-Cage-Auth` and one of two bodies:

```json
{"session": "<the fm_session cookie value>"}
{"username": "...", "password": "..."}
```

**Prefer the session form.** The cookie is scoped to the whole domain, so a
browser signed in to the Slate sends it to The Cage too — asking about it means
nobody types a password twice, and signing out of either tool ends both. In
Node that is roughly:

```js
const token = req.cookies.fm_session;
if (token) {
  const r = await fetch(SLATE_VERIFY_URL, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Cage-Auth': SLATE_VERIFY_SECRET },
    body: JSON.stringify({ session: token }),
  });
  if (r.ok) {
    /* {id, username, display_name, email, phone, department, is_admin,
        role: 'member' | 'manager' | 'admin',   ← their Cage role
        tools: {slate: 'member', cage: 'manager'}} */
  }
}
```

Key The Cage's own records on `id`, which never changes. `username` and `email`
can change (the username follows the email), so update them from each
answer rather than matching on them.

A 401 `no_session` means expired, unknown, or the account has been turned off or deleted —
fall through to The Cage's own sign-in form, which uses the password body.
A 403 `no_access` means a real account whose Cage access is set to No access
on the Team admin page. Show a "no access" message, not the sign-in form.
Both body forms answer the same way.

Session checks are neither throttled nor recorded: the token is 64 hex
characters and either matches a live row or does not, so it is not a guessing
game, and logging every page view would bury the failed sign-ins that
`auth_attempts` exists to show. Password checks are throttled, and share the
Slate's allowance — guessing through The Cage locks the Slate's login too.

`verify.php` needs `cage_auth_secret` in the config, matching The Cage's
`SLATE_VERIFY_SECRET`. Without it the endpoint answers 503 rather than
becoming an unauthenticated password oracle on the public internet.

Its counterpart is `src/slate-auth.js` in the-cage's repository. Two halves of
one contract in two repositories drift without anyone noticing, so when one
changes, copy it across.

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

## How storyboards are stored

Every board is a file in `saved/`, owned by the account that made it, with an
`owner` and `visibility` in its `.meta.json` sidecar. Private is the default;
"Share with Team" flips `visibility` to `team`. Nothing moves on disk when a
board is shared, so versions and trash are unaffected.

Boards saved before ownership existed have neither field. Those are treated as
team-visible with no owner, which is what they effectively were — everything in
`saved/` used to be visible to everyone, and hiding the team's existing work
behind an owner they never had would be worse than leaving it shared.

The browser keeps a copy in IndexedDB so editing stays instant. It syncs up
after a pause in typing and when you leave a board — not on every keystroke,
because boards run to tens of megabytes. A board opened on a machine that has
never seen it is downloaded on demand rather than at menu load, for the same
reason.

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
