# DoCoNEXT Finder

A minimal, **working** Nextcloud app that embodies the DoCoNEXT conventions, so
every new app starts from the same best practices. Scaffold a new app with one
command.

## Create a new app

```bash
cp -r doconext_finder doconext_foo
cd doconext_foo
./init-app.sh --id doconext_foo --name "Foo" --namespace DcnFoo --db-prefix dcn_foo_
```

`init-app.sh` replaces the placeholders everywhere, resets git to a single
initial commit, and removes itself:

| Placeholder | Replaced with | Example |
|---|---|---|
| `doconext_finder` | `--id` (app id / folder / bundle names) | `doconext_foo` |
| `DcnFinder` | `--namespace` (`OCA\<ns>\`) | `DcnFoo` |
| `dcn_finder_` | `--db-prefix` (DB tables) | `dcn_foo_` |
| `DoCoNEXT Finder` | `--name` (default display name) | `Foo` |

Then:

```bash
composer install          # PSR-4 autoloader + dev tools
npm install && npm run build
php occ app:enable doconext_foo
```

## What you get

A three-layer backend + Vue 3 frontend that enables and renders a page, with a
worked **Note** example (entity → mapper → service → controller → migration →
Vue list) you replace with your own domain. Plus all the tooling: `composer`
(lint/cs/psalm/phpunit), `vite`/`eslint`/`stylelint`, `Makefile` (l10n), CI, and
the standardized `deploy.sh`.

```
appinfo/  lib/{AppInfo,Controller,Service,Db,Migration,Settings}  src/  templates/  img/  tests/
```

See **[CLAUDE.md](CLAUDE.md)** for the full conventions (routing, naming, icons,
i18n, quality gates) — it's inherited by every scaffolded app.

## Dev commands

```bash
npm run watch            # rebuild frontend on change
php occ maintenance:repair   # after changing route attributes
composer cs:fix          # auto-format PHP
./deploy.sh --app <id> --user root --host <host> [--container <name>] [--update]
```
