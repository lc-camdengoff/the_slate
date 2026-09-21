# The Slate

Storyboarding tool for the Life.Church filmmaking team.
Live at <https://creativemedia.church/filmmaking/slate/>.

Shot lists live in the browser (IndexedDB) and can be shared with the rest of
the team through a **team library** saved on the server. Everyone signs in with
their own account; signup is self-service behind a shared invite code.

## What's here

| Path | |
| --- | --- |
| `index.php` | the front door: sign-in gate, then serves the app |
| `app.html` | the whole app, one self-extracting file (never served directly) |
| `src/template.html` | readable app source — edit this, not the bundle |
| `tools/bundle.py` | unpack / pack / check the bundle |
| `login.php` `signup.php` `logout.php` `reset.php` | accounts |
| `account.php` `admin.php` | your own settings, and team admin |
| `list.php` `save.php` `load.php` `delete.php` | team library endpoints |
| `auth.php` `db.php` `lib.php` `page.php` `schema.sql` | includes |
| `config.sample.php` | template for the config you upload by hand |
| `.htaccess` `.user.ini` | server config, deployed with the app |
| `server/saved.htaccess` | for the shared folder — uploaded by hand, once |

## Accounts

PostgreSQL holds users, sessions, invite codes and reset codes. The host has no
outbound mail, so nothing depends on email:

- **Signing up** needs an invite code, created by an admin on `admin.php`.
- **The first account** uses the `bootstrap_code` from the config file and
  becomes the admin. That code stops working once it exists.
- **A forgotten password** is an admin generating a single-use reset code and
  handing it over directly.

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
