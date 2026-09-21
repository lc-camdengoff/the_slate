# Deploying The Slate

The tool lives at <https://creativemedia.church/filmmaking/slate/> on Namecheap
shared cPanel hosting (LiteSpeed, cPanel user `creaueyu`).

```
/home/creaueyu/
├── .slate-config.php       database details — uploaded by hand, never in git
└── public_html/filmmaking/
    ├── .htaccess           cPanel Directory Privacy (see "Cutover" below)
    ├── index.html          public "Filmmaking Team" page
    ├── slate/              ← deployed from this repo
    │   ├── index.php       the front door: sign-in gate, then serves the app
    │   ├── app.html        the app itself — blocked from direct requests
    │   ├── login.php  signup.php  logout.php  reset.php  account.php  admin.php
    │   ├── list.php  save.php  load.php  delete.php
    │   ├── lib.php  auth.php  db.php  page.php  schema.sql
    │   └── .htaccess  .user.ini
    └── saved/              ← the team's storyboards, NEVER deployed
```

`saved/` is deliberately a sibling of `slate/`, outside the deploy target, and
`**/saved/**` is excluded from the workflow, so a deploy can never delete the
team's work.

## Automatic deploys

Pushing to `main` runs `.github/workflows/deploy.yml`, which mirrors the repo
root into `slate/` over FTPS. `src/`, `tools/`, `server/` and the markdown files
are excluded — they are development-side only.

## Accounts

The team signs in with their own accounts, stored in PostgreSQL. Signup is
self-service, gated by a shared invite code an admin creates. **This host has
no outbound mail**, so nothing depends on email: there are no verification
links, and a forgotten password is an admin generating a one-time code and
handing it over directly.

### 1. Create the database

cPanel → **PostgreSQL Databases**. Create a database and a user, and grant the
user access to the database. cPanel prefixes both names with the account, so
`slate` becomes `creaueyu_slate`.

### 2. Upload the config

Copy `config.sample.php` to `/home/creaueyu/.slate-config.php` — **above**
`public_html`, so the file holding the database password is never web-served.
Fill in the database details and change `bootstrap_code` to something only you
know.

`db.php` also accepts `slate/config.php` if you must keep it inside the folder;
`.htaccess` blocks direct requests for it, and `.gitignore` keeps both names
out of git. Above the web root is better.

### 3. Create the first account

Visit `slate/signup.php`. While no accounts exist, the page asks for the
**setup code** — that is `bootstrap_code` — and the account it creates is the
admin. The code stops working the moment that account exists.

Then, as that admin, open `slate/admin.php` and create an invite code to share
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
