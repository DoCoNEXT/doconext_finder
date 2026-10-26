# Changelog

All notable changes to Finder are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the version
numbers are the ones in `appinfo/info.xml`.

## [Unreleased]

## [0.11.0] - 2026-10-26

### Added
- Edit a file from its menu, in the editor the Files app would open it in.

### Changed
- Show in Files opens the file's folder with the file selected, instead of
  opening the file itself; a folder still opens. The full preview has the
  layout of DoCoNEXT Core's.
- Sortable column headers look and behave as Core's: an arrow on hover or once
  the column sorts, and a tooltip saying what a click does. Names start A→Z;
  dates, sizes and numeric metadata start newest or largest first.

### Fixed
- An action menu stayed open after an item in it was picked.

## [0.10.0] - 2026-10-20

### Added
- Share a saved search with people, groups or teams, read-only or with the
  right to edit it. What is shared is the search, never its results: whoever
  runs it sees only the files they can open themselves. Who may be shared with
  follows Nextcloud's own sharing settings.
- Searches shared with you are listed under "Shared with me" and offered by the
  search box. You can run, copy or remove them from your own list; only the
  owner deletes a search.
- A notification when someone shares a search with you, and a link that opens
  a saved search straight away.
- A description for a saved search.
- Resizable columns: drag a column heading's edge, or double-click it to let
  the column size itself again. Widths are kept per browser, like the width of
  the details panel.

### Changed
- Saving, renaming and sharing a search happen in one dialog instead of the
  browser's own prompts.
- A search that looks in a folder or dossier you cannot open says so, instead
  of reporting no files found.
- On the Searches page, a search without filters says "No filters", so the
  rows line up.

## [0.9.2] - 2026-10-14

### Changed
- The filters in force are easier to see. The Filters button counts them;
  folded, each one is a chip that removes it, with a button to clear them all;
  unfolded, the value of each filter in force is drawn bold in the theme's
  accent colour.

## [0.9.1] - 2026-10-12

### Added
- Structured search on name, size, created and modified date, matching all
  conditions or any of them; date filters mean calendar periods, as the Files
  app does.
- Search inside file contents on request, next to the name search that always
  runs, when the Full text search app is installed and indexing.
- Saved searches, with recent searches kept automatically.
- Configurable columns: choose, order and rename them. The column headings and
  the row menu stay in view while scrolling, and the name stays the first column.
- Grouping by type, folder or date, stacked in several levels.
- A favorites page, browsed and toggled from the same grid.
- File commands from the result row: open in Files or the desktop app, go to
  the containing folder, preview, download, upload a new version, add files to
  a folder. Adding files never overwrites one with the same name; Finder says
  which ones it skipped.
- Commands follow the file's own rights, as the Files app does: no preview,
  download or opening locally for a file that may be listed but not read, and
  no download where a share forbids it.
- A resizable details panel.
- With the DoCoNEXT Files Preview app: the file itself in the details panel and
  a full-screen preview, with the searched-for words marked in a colour of your
  choice.
- With DoCoNEXT Core: filters, grouping and columns on Core's metadata fields,
  under the names each workspace gives them; narrowing a search to a
  workspace, an entity type or a single entity; turning a question in plain
  language into filters; opening a result's entity in Core.
- Admin and personal settings.
- Dutch translation.
- Support for Nextcloud 33 to 35.
