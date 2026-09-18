# The Slate

Storyboarding tool for the Life.Church filmmaking team.
Live at <https://creativemedia.church/filmmaking/slate/> (password protected).

Shot lists live in the browser (IndexedDB) and can be shared with the rest of
the team through a **team library** saved on the server.

## What's here

| Path | |
| --- | --- |
| `storyboard.html` | the whole app, one self-extracting file |
| `src/template.html` | readable app source — edit this, not the bundle |
| `tools/bundle.py` | unpack / pack / check the bundle |
| `list.php` `save.php` `delete.php` `lib.php` | team library endpoints |
| `.htaccess` `.user.ini` | server config, deployed with the app |
| `server/saved.htaccess` | for the shared folder — uploaded by hand, once |

## Working on it

```sh
python3 tools/bundle.py unpack   # if src/template.html is missing
# edit src/template.html
python3 tools/bundle.py pack     # rebuild storyboard.html
python3 tools/bundle.py check    # confirm the two are in sync
```

Commit both `src/template.html` and `storyboard.html`. Pushing to `main`
deploys to `slate/` over FTPS.

See [DEPLOY.md](DEPLOY.md) for the hosting layout, the one-time server setup
and how to recover a deleted storyboard.
