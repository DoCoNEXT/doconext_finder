# DoCoNEXT Finder

A search page for your Nextcloud files, built for people who look things up all
day. Structured conditions, saved searches, configurable columns and grouping —
the things the Files app deliberately leaves out.

Finder works on a **plain Nextcloud**; nothing else needs to be installed.

## Features

- **Structured search** — combine conditions on name, size, created and
  modified date, matching all of them or any of them. Date filters mean
  calendar periods, as the Files app does.
- **Search inside files** — on request, next to the name search that always
  runs, when the Full text search app is installed and indexing.
- **Saved searches** — name a search you run often; recent searches are kept
  automatically.
- **Configurable columns** — choose which columns the result grid shows, in
  which order, and rename them to your own vocabulary.
- **Grouping** — by type, folder or date, stacked in several levels.
- **Favorites** — browse and toggle your favorite files from the same grid.
- **File commands** — open in Files or in the desktop app, go to the containing
  folder, preview, download, upload a new version or add files to a folder,
  straight from the result row.
- **Details panel** — resizable, with rich previews when the Files Preview app
  is installed.
- Admin and personal settings, and a Dutch translation.

## Requirements

- Nextcloud 33, 34 or 35
- PHP 8.2 or newer

## Installation

Install **DoCoNEXT - Finder** from the Apps page of your Nextcloud
(category *Files*), or from [apps.nextcloud.com](https://apps.nextcloud.com).

## Finder and DoCoNEXT Core

Finder is the free, open half of the DoCoNEXT product; DoCoNEXT Core is
available from DoCoNEXT, not from the App Store. Finder has no dependency on
Core: no `<dependencies>` entry, no compile-time coupling, no shared tables.
When Core is installed on the same server, Finder resolves Core's public
services by name and additionally filters and groups on Core's metadata
fields, scoped by workspace and entity. When it is not, Finder answers as if
there were no Core. The browser only ever talks to Finder.

## Development

The Node version is pinned in `.nvmrc`; `npm install` refuses an older one.

```bash
nvm use
composer install                     # autoloader + dev tools (psalm, phpunit, …)
npm ci && npm run build              # or: npm run watch
php occ app:enable doconext_finder
```

Quality gates, all of which run in CI (`.github/workflows/ci.yml`):

```bash
composer lint && composer cs:check && composer psalm && composer test:unit
composer test:platform               # core search classes against a server checkout
npm run lint && npm run stylelint && npm run typecheck && npm test
make appstore                        # the release tarball
```

### Search backend

Search goes through `\OCP\Files\Folder::search()` — the same engine the WebDAV
DASL backend drives, reached directly. That matters: DASL only exposes the
properties `FileSearchBackend` chooses to declare, whereas the operator tree
accepts every filecache column in `SearchBuilder::$fieldTypes` (`path`,
`favorite` and `tagname` among them) and arbitrary and/or/not nesting.

Two sharp edges are handled in `lib/Search/FileCondition.php`:

- **Not every operator works on every field.** `SearchBuilder::validateComparison()`
  has a per-field whitelist; the cross-product would otherwise produce 500s.
- **`favorite` is a presence flag, not a boolean.** The builder rewrites it to
  `tag.category = <favorite tag>` and *discards* the supplied value, so
  `favorite eq false` would silently return favorites. Negating it does not help
  either — tag fields are joined, so `NOT` compares a NULL column and matches
  nothing. "Not favorited" is therefore rejected rather than answered wrongly.

## Security

Please report vulnerabilities privately; see [SECURITY.md](SECURITY.md).

## License

AGPL-3.0-or-later. Licensing follows the [REUSE](https://reuse.software)
layout: the licence texts are in `LICENSES/`, and `REUSE.toml` says which
covers which files.
