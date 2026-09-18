# Deploying The Slate

The tool lives at <https://creativemedia.church/filmmaking/slate/> on Namecheap
shared cPanel hosting (LiteSpeed, cPanel user `creaueyu`).

```
public_html/filmmaking/
├── .htaccess          basic auth for the whole folder (cPanel Directory Privacy)
├── index.html         public "Filmmaking Team" page
├── slate/             ← deployed from this repo
│   ├── storyboard.html
│   ├── list.php  save.php  delete.php  lib.php
│   ├── .htaccess  .user.ini
└── saved/             ← the team's storyboards, NEVER deployed
```

`saved/` is deliberately a sibling of `slate/`, outside the deploy target, and
`**/saved/**` is excluded from the workflow, so a deploy can never delete the
team's work.

## Automatic deploys

Pushing to `main` runs `.github/workflows/deploy.yml`, which mirrors the repo
root into `slate/` over FTPS. `src/`, `tools/`, `server/` and the markdown files
are excluded — they are development-side only.

## One-time manual steps

These cannot be automated, because they touch files outside the deploy target.

### 1. Protect the saved folder

Upload `server/saved.htaccess` to `public_html/filmmaking/saved/.htaccess`
(cPanel → File Manager, or any FTP client). It stops anything in that folder
from being executed or listed, and serves nothing but `.json`.

It deliberately contains no `Require` directives: basic auth for `saved/` is
inherited from `filmmaking/.htaccess`, and an access directive there would
*override* the parent's `Require valid-user` and expose the folder.

### 2. Check the folder is writable

`saved/` must be writable by the PHP user (`creaueyu`). `755` is enough under
suEXEC. If saves fail with `storage_unavailable`, this is why.

### 3. Verify the install

Sign in and open <https://creativemedia.church/filmmaking/slate/list.php>. You
should get JSON like:

```json
{"ok":true,"user":"camden","userSource":"PHP_AUTH_USER","maxBytes":33554432,...}
```

- **`user` is your basic-auth username** — attribution works. If it is `null`,
  the server is not passing the name through to PHP and the app will ask each
  person to type their name once instead. Nothing else breaks.
- **`maxBytes` is the largest storyboard the server will accept.** It should be
  around 33 MB. If it comes back near 8 MB, `.user.ini` has not taken effect:
  wait five minutes (`user_ini.cache_ttl`), then raise `post_max_size` and
  `memory_limit` in cPanel → MultiPHP INI Editor instead.

Storyboards inline their reference images as base64 JPEGs, so a 30-shot board
is routinely several megabytes — this limit matters. The app reads `maxBytes`
before every save and refuses oversized boards with a message that tells the
user to remove some images, because a body over `post_max_size` is discarded by
PHP before the script runs and would otherwise fail silently.

Also confirm <https://creativemedia.church/filmmaking/slate/> loads the app
(that is `DirectoryIndex` in `slate/.htaccess`).

Those two checks double as confirmation that the dotfiles deployed: a sensible
`maxBytes` means `.user.ini` arrived, and the bare folder URL loading the app
means `.htaccess` did. If either is missing from `slate/`, upload it by hand —
some FTP clients and sync tools skip dotfiles.

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

## Editing the app

`storyboard.html` is a self-extracting bundle: an unpacker, a gzip+base64
manifest of libraries and fonts, and the actual app as one JSON-escaped string.
Editing that string directly makes every change a single unreadable 100 KB line
in git, so the readable source is `src/template.html`:

```sh
python3 tools/bundle.py unpack   # storyboard.html -> src/template.html
# edit src/template.html
python3 tools/bundle.py pack     # src/template.html -> storyboard.html
python3 tools/bundle.py check    # verify the two are in sync before committing
```

Commit both files. `pack` reproduces the bundler's exact encoding, so an
unpack/pack round-trip with no edits leaves `storyboard.html` byte-identical.

## Requirements

PHP 7.4 or newer. On PHP 8.3+ uploads are validated with `json_validate()`,
which checks a 20 MB board without building it in memory; older versions fall
back to `json_decode()`.
