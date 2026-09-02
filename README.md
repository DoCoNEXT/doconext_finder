# DoCoNEXT Finder

A search page for your Nextcloud files, built for people who look things up all
day. Structured conditions, saved searches, configurable columns and grouping —
the things the Files app deliberately leaves out.

Finder works on a **plain Nextcloud**. When the DoCoNEXT Core app is also
installed it additionally filters and groups on Core's metadata fields.

## Status

Early. What exists today:

- `POST /api/search` — structured file search over the user's own files.
  A free-text term plus a list of conditions, combined with **and** or **or**.
- `GET /api/search/fields` — the filterable surface this server offers, so the
  UI builds its menus from the backend instead of a hardcoded copy.
- A first search page: term, condition builder, result table, paging.

Not yet: saved searches, column configuration, grouping, favorites browsing,
Core metadata integration. The template's `Note` example is still present and
unused — removing it is the next cleanup.

## Why its own app

Finder is published to the Nextcloud app store; Core is not. That makes Finder
the free, open half of the product and means it must carry **no** dependency on
Core — no `<dependencies>` entry, no `\OCA\DcnCore\` classes, no shared tables.
Where the two meet, they meet in the browser, over Core's own HTTP routes.

See `docs/nextcloud-app-migration.md` in the `doconext-finder` (desktop) repo
for the full rationale.

## Development

```bash
composer install                     # PSR-4 autoloader + dev tools
npm install && npm run build         # or: npm run watch
php occ app:enable doconext_finder
```

Note: `vimeo/psalm ^5` does not support PHP 8.5, so run `composer` inside the
Nextcloud container (PHP 8.2) rather than on a host with a newer PHP.

### Search backend

Search goes through `\OCP\Files\Folder::search()` — the same engine the WebDAV
DASL backend drives, reached directly. That matters: DASL only exposes the
properties `FileSearchBackend` chooses to declare, whereas the operator tree
accepts every filecache column in `SearchBuilder::$fieldTypes` (`path`,
`favorite` and `tagname` among them) and arbitrary and/or/not nesting.

Two sharp edges are handled in `lib/Search/FileCondition.php`, both verified
against a live server:

- **Not every operator works on every field.** `SearchBuilder::validateComparison()`
  has a per-field whitelist; the cross-product would otherwise produce 500s.
- **`favorite` is a presence flag, not a boolean.** The builder rewrites it to
  `tag.category = <favorite tag>` and *discards* the supplied value, so
  `favorite eq false` would silently return favorites. Negating it does not help
  either — tag fields are joined, so `NOT` compares a NULL column and matches
  nothing. "Not favorited" is therefore rejected rather than answered wrongly.
