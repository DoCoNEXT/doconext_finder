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

## Translations
NC loads `l10n/<lang>.json` (PHP) + `l10n/<lang>.js` (browser). Both must exist + stay in sync per language. `make l10n-pot` extracts strings; `make l10n` regenerates from `.po`. Missing keys fall back to English.

## Quality gates
```
composer lint          # PHP syntax
composer cs:check      # PSR-12 (cs:fix to apply)
composer psalm         # static analysis
composer test:unit     # PHPUnit
npm run lint / stylelint / build / test
```

## Dev + deploy
```
php occ app:enable <id>
php occ maintenance:repair          # after route/attr changes
npm run watch                       # frontend watch
./deploy.sh --app <id> --user root --host <host> [--container <name>] [--update]
```

## The example domain
The template ships a working **Note** example (entity + mapper + service + controller + migration + Vue list). Delete it once you scaffold your own domain — it exists only to demonstrate the layers end-to-end.
