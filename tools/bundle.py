#!/usr/bin/env python3
"""Unpack / repack the app source inside app.html.

app.html is a self-extracting bundle: a small unpacker, a
`__bundler/manifest` script holding gzip+base64 resources (html2canvas, jsPDF,
the DC runtime, fonts), and a `__bundler/template` script holding the actual
app as one JSON-escaped string. Editing that string in place makes every change
look like a single modified 100 KB line, so the readable source lives in
src/template.html and is packed back in before committing.

    python3 tools/bundle.py unpack   # app.html -> src/template.html
    python3 tools/bundle.py pack     # src/template.html -> app.html
    python3 tools/bundle.py check    # verify the two are in sync

The encoding (`ensure_ascii=False` plus `</` -> `<\\u002F`) reproduces the
original bundle byte for byte; `check` enforces that so a pack can never
silently corrupt the file.
"""

import json
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
BUNDLE = os.path.join(ROOT, 'app.html')
SOURCE = os.path.join(ROOT, 'src', 'template.html')

# The payload sits on its own line between these markers.
TEMPLATE_RE = re.compile(
    r'(<script type="__bundler/template">\n)(.*?)(\n  </script>)', re.S)


def encode(template):
    """JSON-encode the app source the way the bundler does.

    `</` must not survive literally or it would close the enclosing <script>.
    """
    return json.dumps(template, ensure_ascii=False).replace('</', '<\\u002F')


def read_bundle():
    with open(BUNDLE, encoding='utf-8') as fh:
        bundle = fh.read()
    match = TEMPLATE_RE.search(bundle)
    if not match:
        sys.exit('error: no __bundler/template block in app.html')
    return bundle, match


def unpack():
    _, match = read_bundle()
    template = json.loads(match.group(2))
    os.makedirs(os.path.dirname(SOURCE), exist_ok=True)
    with open(SOURCE, 'w', encoding='utf-8') as fh:
        fh.write(template)
    print('unpacked %d bytes -> src/template.html' % len(template))


def pack():
    bundle, match = read_bundle()
    with open(SOURCE, encoding='utf-8') as fh:
        template = fh.read()

    encoded = encode(template)
    if json.loads(encoded) != template:
        sys.exit('error: template does not survive a JSON round-trip')

    updated = bundle[:match.start(2)] + encoded + bundle[match.end(2):]
    with open(BUNDLE, 'w', encoding='utf-8') as fh:
        fh.write(updated)
    print('packed %d bytes -> app.html%s' % (
        len(template), '' if updated != bundle else ' (no change)'))


def check():
    _, match = read_bundle()
    with open(SOURCE, encoding='utf-8') as fh:
        template = fh.read()
    if match.group(2) != encode(template):
        sys.exit('error: app.html is stale — run: python3 tools/bundle.py pack')
    print('ok: app.html matches src/template.html')


COMMANDS = {'unpack': unpack, 'pack': pack, 'check': check}

if __name__ == '__main__':
    if len(sys.argv) != 2 or sys.argv[1] not in COMMANDS:
        sys.exit('usage: bundle.py {%s}' % '|'.join(COMMANDS))
    COMMANDS[sys.argv[1]]()
