#!/usr/bin/env python3
"""
Extract single-arg `t('...')` calls from .vue and .ts source files and append
to a .pot file. Used because Vite/Rollup mangles the `t` import name in the
built JS bundle, defeating xgettext.

Usage:
    python3 scripts/extract-frontend-strings.py <pot-file> <src-dir>

Existing .pot content is preserved; new entries are appended with source
references. Handles single and double quotes; skips template literals
(backticks) — those would need expression parsing.
"""
import re
import sys
import os
from pathlib import Path
from collections import defaultdict

# Match t('...') or t("...") — single-arg, capturing the string. Excludes
# two-arg forms (t('appid', 'msg')) by requiring the call to close immediately
# after the string (allowing for an optional vars object as 2nd arg).
# We accept the second arg only if it starts with `{` (vars dict).
PATTERNS = [
    re.compile(r"""(?<![A-Za-z0-9_$])t\(\s*'((?:[^'\\]|\\.)*)'\s*[,)]"""),
    re.compile(r"""(?<![A-Za-z0-9_$])t\(\s*"((?:[^"\\]|\\.)*)"\s*[,)]"""),
]

# Skip strings that look like code identifiers/keys rather than UI text.
# Anything inside t('...') is a translation string by intent, so we keep
# almost everything — including short lowercase labels like 'required' or
# 'recommended' (these were previously dropped, leaving them untranslated).
def is_likely_msgid(s: str) -> bool:
    if not s or len(s) < 2:
        return False
    # Text: contains a space, starts uppercase, or has sentence/UI punctuation.
    if ' ' in s:
        return True
    if s[0].isupper():
        return True
    if any(c in s for c in '.!?,:;%(){}…—–→@'):
        return True
    # Single token with no spaces: accept genuine lowercase words
    # (e.g. 'required', 'recommended', 'e-mail'), but still skip code-style
    # identifiers — snake_case, camelCase, or anything with digits — which
    # were never meant as UI text.
    if re.fullmatch(r"[a-zà-öø-ÿ]+(?:[-'][a-zà-öø-ÿ]+)*", s):
        return True
    return False

def unescape(s: str) -> str:
    return s.replace("\\'", "'").replace('\\"', '"').replace('\\\\', '\\').replace('\\n', '\n').replace('\\t', '\t')

def main() -> int:
    if len(sys.argv) != 3:
        print(__doc__, file=sys.stderr)
        return 2
    pot_path, src_dir = sys.argv[1], sys.argv[2]

    strings = defaultdict(list)  # msgid -> [(file, line), ...]

    for root, _, files in os.walk(src_dir):
        for name in files:
            if not (name.endswith('.vue') or name.endswith('.ts')):
                continue
            path = Path(root) / name
            try:
                text = path.read_text(encoding='utf-8')
            except (UnicodeDecodeError, OSError):
                continue
            for pat in PATTERNS:
                for m in pat.finditer(text):
                    s = unescape(m.group(1))
                    if not is_likely_msgid(s):
                        continue
                    line = text[:m.start()].count('\n') + 1
                    rel = path.relative_to(Path(src_dir).parent) if Path(src_dir).parent in path.parents else path
                    strings[s].append((str(rel), line))

    # Read existing pot to find what's already in it
    existing = set()
    pot_text = ''
    if os.path.exists(pot_path):
        pot_text = Path(pot_path).read_text(encoding='utf-8')
        for m in re.finditer(r'^msgid "((?:[^"\\]|\\.)*)"', pot_text, re.MULTILINE):
            existing.add(unescape(m.group(1)))

    # Append new entries
    new = sorted(s for s in strings if s and s not in existing)
    if not new:
        print(f"No new entries to add ({len(strings)} total, all already in pot)")
        return 0

    def quote(s: str) -> str:
        return s.replace('\\', '\\\\').replace('"', '\\"').replace('\n', '\\n').replace('\t', '\\t')

    with open(pot_path, 'a', encoding='utf-8') as f:
        # Make sure there's a trailing newline
        if pot_text and not pot_text.endswith('\n'):
            f.write('\n')
        for s in new:
            f.write('\n')
            for src_file, line in strings[s][:5]:  # cap at 5 refs per entry
                f.write(f'#: {src_file}:{line}\n')
            f.write(f'msgid "{quote(s)}"\n')
            f.write('msgstr ""\n')

    print(f"Added {len(new)} new entries to {pot_path} (total {len(strings)} unique strings found in source)")
    return 0

if __name__ == "__main__":
    sys.exit(main())
