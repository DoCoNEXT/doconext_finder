# Changelog

All notable changes to Finder are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the version
numbers are the ones in `appinfo/info.xml`.

## [Unreleased]

## [0.9.2] - 2026-10-14

### Changed
- The filters in force are easier to see. The Filters button counts them;
  folded, each one is a chip that removes it, with a button to clear them all;
  unfolded, the value of each filter in force is drawn bold in the theme's
  accent colour.

## [0.9.1] - 2026-10-12

First release.

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
