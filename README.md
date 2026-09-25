# The Slate

Storyboarding tool for the Life.Church filmmaking team.
Live at <https://creativemedia.church/filmmaking/slate/>.

Storyboards are saved to the server against your account, so they follow you
to any computer you sign in on. They are private until you **Share with Team**,
which puts them in the shared library. The browser keeps a local copy so
editing stays instant; the server copy is the durable one.

Sign-in is shared across every tool under `/filmmaking/` — this repo deploys
both The Slate and that shared `auth/` folder. Signup is self-service behind an
invite code an admin creates.

## What's here

| Path | |
| --- | --- |
| `index.php` | the front door: requires a session, then serves the app |
| `app.html` | the whole app, one self-extracting file (never served directly) |
| `src/template.html` | readable app source — edit this, not the bundle |
| `tools/bundle.py` | unpack / pack / check the bundle |
| `list.php` `save.php` `load.php` `delete.php` | team library endpoints |
| `lib.php` `diag.php` | storyboard helpers, and an install probe |
| `auth/` | **shared sign-in for every tool under /filmmaking/** |
| `auth/login.php` `signup.php` `logout.php` `reset.php` | accounts |
| `auth/account.php` `admin.php` | your own settings, and team admin |
| `auth/person.php` `import.php` | edit one person and their access; import a CSV |
| `auth/verify.php` | lets The Cage ask who a session belongs to |
| `auth/auth.php` `db.php` `page.php` `people.php` `tools.php` `email.php` `schema.sql` | includes |
| `auth/config.sample.php` | template for the config you upload by hand |
| `.htaccess` `.user.ini` | server config, deployed with the app |
| `server/saved.htaccess` | for the shared folder — uploaded by hand, once |

## Accounts

PostgreSQL holds users, sessions, invite codes and reset codes, shared by every
tool under `/filmmaking/`. The host has no outbound mail, so nothing depends on
email:

- **Signing up** needs an invite code, created by an admin on `admin.php`.
- **The first account** uses the `bootstrap_code` from the config file and
  becomes the admin. That code stops working once it exists.
- **A forgotten password** is an admin generating a single-use reset code and
  handing it over directly.
- **Bringing people over** from Cheqroom or a spreadsheet is a CSV import on
  the admin page. Each new person gets a one-time setup code and chooses their
  own password.
- **Usernames** are always the part of the email before the @ (first.last).
  Changing someone's email renames them, and their storyboards follow.
- **Deleting someone** moves their private storyboards to the Slate's trash
  and keeps their team ones. Turning the account off keeps everything.
- **Access** is set per person and per tool (the Slate, The Cage and whatever
  comes next), with each tool's own roles, e.g. Cage Gear manager.

A new tool joins the same login with two lines — see
[DEPLOY.md](DEPLOY.md#adding-another-tool).

## Working on it

```sh
python3 tools/bundle.py unpack   # if src/template.html is missing
# edit src/template.html
python3 tools/bundle.py pack     # rebuild app.html
python3 tools/bundle.py check    # confirm the two are in sync
```

Commit both `src/template.html` and `app.html`. Pushing to `main` deploys to
`slate/` over FTPS.

See [DEPLOY.md](DEPLOY.md) for the hosting layout, the database setup, the
cutover from cPanel Directory Privacy, and how to recover a deleted storyboard.
