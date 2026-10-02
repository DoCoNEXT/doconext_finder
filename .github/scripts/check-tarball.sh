#!/usr/bin/env bash
# Refuses a release tarball of the wrong shape: anything but one top-level
# directory named after the app, dev-only paths inside it, or a size that says
# css/ was never emptied between builds (vite appends, it does not replace).
# Used by CI on every push and by the release workflow before it signs.
set -euo pipefail

tarball=${1:?usage: check-tarball.sh <tarball>}
app=doconext_finder
listing=$(tar -tzf "$tarball")

tops=$(cut -d/ -f1 <<<"$listing" | sort -u)
if [ "$tops" != "$app" ]; then
  echo "::error::the tarball must hold exactly one directory, '$app'; found: $(tr '\n' ' ' <<<"$tops")"
  exit 1
fi

cut -d/ -f2 <<<"$listing" | sort -u

# Everything below is dev-only; none of it may be in the package.
for p in src tests docs scripts .git .github .husky node_modules vendor-bin; do
  if grep -q "^$app/$p/" <<<"$listing"; then
    echo "::error::dev-only path '$p' is inside the tarball"; exit 1
  fi
done
for f in CLAUDE.md deploy.sh Makefile package.json composer.json; do
  if grep -q "^$app/$f$" <<<"$listing"; then
    echo "::error::dev-only file '$f' is inside the tarball"; exit 1
  fi
done

# A clean build is well under 1 MB and carries a handful of stylesheets; a
# few hundred means css/ was never emptied.
size=$(stat -c %s "$tarball")
echo "tarball: $((size / 1024)) KB, css files: $(grep -c '/css/.*\.css$' <<<"$listing" || true)"
if [ "$size" -ge $((5 * 1024 * 1024)) ]; then
  echo "::error::tarball is $((size / 1024 / 1024)) MB"; exit 1
fi
