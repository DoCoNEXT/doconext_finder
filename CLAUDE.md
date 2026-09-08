# DoCoNEXT Nextcloud App — Conventions

This file is inherited by every app scaffolded from `doconext_finder`.
Keep it; prune the parts that don't apply and add app-specific context.

## Tech Stack
- **Backend**: PHP 8.2+, Nextcloud App Framework (Nextcloud 31–34)
- **Frontend**: Vue 3 + TypeScript (`<script setup>`), Nextcloud Vue components, Pinia
- **Build**: `@nextcloud/vite-config` → `js/` + `css/`
- **Architecture**: three-layer — Controller → Service → Mapper

## Structure
```
appinfo/       info.xml (id/version/deps/settings), routes.php (empty — see below)
lib/
├── AppInfo/   Application.php (bootstrap), AppConstants.php (APP_ID + display name)
├── Controller/ thin HTTP layer (ApiController for /api/*; PageController for pages)
├── Service/    business logic — the ONLY layer that talks to Mappers
├── Db/         Entity + QBMapper per table
├── Migration/  VersionXXXXXXDateYYYYMMDDHHMMSS.php
└── Settings/   Admin/Personal Settings + Section
src/           Vue: main.ts, admin/personal-settings.ts entries, views/, components/,
               composables/, services/, stores/, types/, constants.ts
templates/     PHP mount points (one <div id> per bundle)
img/           app.svg (white-filled) + app-dark.svg (black-filled)
```

## Routing (important)
- Routes are `#[FrontpageRoute(verb, url)]` attributes on controller methods — **NOT** `#[ApiRoute]` (that registers under `/ocs/v2.php/…`). App endpoints live under `/apps/<id>/…`.
- `appinfo/routes.php` stays empty (NC just needs the file to exist).
- **Method declaration order = route registration order.** Declare literal-path routes (`/api/notes/recent`) BEFORE parametric ones (`/api/notes/{id}`), or `{id}` swallows the literal.
- After changing route attributes: `php occ maintenance:repair` (clears the route cache).
- API controllers extend `ApiController` (CORS preflight); page controllers extend `Controller`.

## Backend conventions
- PSR-12, type hints everywhere. Controllers stay thin; logic lives in Services; only Services use Mappers.
- API request bodies are **snake_case**; PHP return arrays + all TS types are **camelCase** (`Entity::toArray()`).
- Migration index/FK/constraint names must be **globally unique across the whole NC schema** — prefix with the app's DB prefix.
- The user-facing product name is **configurable** (app-config `display_name`) with a functional fallback (`AppConstants::DEFAULT_DISPLAY_NAME`) — never surface the raw app id.

## Frontend conventions
- **Always** use the `useI18n` composable — never import `translate` from `@nextcloud/l10n` directly. Key = the English string.
- **Always** use `<NcButton>` with `variant=` (`primary`|`secondary`|`tertiary`|`error`|`warning`) — never a plain `<button>`, never `type=`.
- Theme-safe colors: use `var(--color-primary-element)` / `-element-light` (never raw `var(--color-primary)`) for foreground/borders; `-text`-suffixed vars for text.
- Read bootstrap data synchronously via `loadState` (`src/constants.ts`) — the PHP handler must call `InitialStateProvider::provide()`.
- `NcCheckboxRadioSwitch` for toggles; `:deep()` to override styles inside Nc components.
- UI icons come from **`@lucide/vue`** (`<Star :size="20" />`) — the set the other DoCoNEXT apps draw with — never `@mdi/js`, and never `NcIconSvgWrapper` around a path. A slotted Lucide `<svg>` is neither `.material-design-icon` nor `.icon-vue`, so it misses the icon box `NcActionButton`/`NcActionLink` size by class; `src/styles/action-icons.scss` gives it back (global, because NcActions teleports to `<body>`).

## Icons (NC33 + NC34)
Ship **only** `img/app.svg` (white-filled, `fill="#ffffff"`) + `img/app-dark.svg` (black-filled). Draw both as **filled silhouettes with negative-space detail** — never stroke outlines (they collapse when NC force-fills `currentColor`). Do NOT add an app-id-named `img/<id>.svg` (breaks NC33).

Put the `fill` on the **root `<svg>` element and nowhere else** — exactly how core does it (`apps/files/img/app.svg`). The apps page (`apps/appstore`) inlines the file through `NcIconSvgWrapper`, whose `svg { fill: currentColor }` beats the presentation attribute on the root but loses against a `fill` on each `<path>`/`<rect>`; a per-child `fill="#ffffff"` therefore renders white-on-white, i.e. no icon at all in the list. (The app sidebar's `useAppIcon.ts` rewrites `fill="#fff"` to `currentColor` itself, so the bug shows up **only** in the apps list — check there, not in the sidebar.) Negative-space detail must be holes via `fill-rule="evenodd"` in one compound path, not white-filled shapes on top.

## Licensing
REUSE layout, like every Nextcloud app: full licence texts in `LICENSES/`, `REUSE.toml` says which covers what (directory-level annotations, not per-file SPDX headers), and CI runs `fsfe/reuse-action`.

`LICENSES/` holds **only** licences that a *tracked* file actually uses — `reuse lint` fails on a licence text nothing references, and that is a red CI, not a warning. So do **not** add a licence text when you add a dependency: `js/` and `css/` are generated and never in git, and each built bundle ships its own `js/<bundle>.mjs.license` sidecar (written by `@nextcloud/vite-config`) naming every package compiled in. That sidecar *is* the attribution. Add a licence text only when you check a third-party **file** into the repo — an icon copied from Lucide, say — and give it its own `[[annotations]]` block. Do not hand-maintain a third-party licence document; nothing in the NC ecosystem ships one.

## Translations
NC loads `l10n/<lang>.json` (PHP) + `l10n/<lang>.js` (browser). Both must exist + stay in sync per language. `make l10n-pot` extracts strings into `translationfiles/`; `make l10n-from-po` writes a translated `.po` back **over** `l10n/<lang>.*` — run it only for a language whose `.po` is complete, or it ships English where you had translations. Missing keys fall back to English.

## Quality gates
CI runs every one of these; `.github/workflows/ci.yml` is the source of truth.
```
composer lint          # PHP syntax (CI: 8.2–8.5)
composer cs:check      # PSR-12 (cs:fix to apply)
composer psalm         # static analysis; tests/psalm-baseline.xml holds the
                       # known-and-accepted findings — never grow it to silence
                       # a new one, fix the code instead
composer test:unit     # PHPUnit (CI: 8.2–8.5, plus a run against the NC34 stubs)
npm run lint / stylelint / build / test
make appstore          # the release tarball, and what CI checks the shape of
```
Psalm needs `<extraFiles><directory name="vendor"/></extraFiles>` in `psalm.xml`: without it, it never reads `vendor/nextcloud/ocp` and reports every OCP class as undefined.

## Dev + deploy
The toolchain is pinned in `.nvmrc` (node 24); `npm`'s `preinstall` hook refuses
an older one rather than letting it fail strangely later.
```
nvm use                             # before any npm command
php occ app:enable <id>
php occ maintenance:repair          # after route/attr changes
npm run watch                       # frontend watch
./deploy.sh --host <host> [--user root] [--container <name>] [--update]
```
`deploy.sh` uploads exactly what `make appstore` builds — one exclude list, in
the Makefile — and puts the dev composer dependencies back afterwards, since
the release build strips them from this working tree.

## The example domain
The template ships a working **Note** example (entity + mapper + service + controller + migration + Vue list). Delete it once you scaffold your own domain — it exists only to demonstrate the layers end-to-end.
