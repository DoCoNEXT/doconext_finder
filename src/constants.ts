/**
 * App-wide constants, hydrated synchronously from the server-provided initial
 * state (InitialStateProvider::provide() → loadState). No network round-trip.
 *
 * Every PHP handler that loads one of the app's bundles MUST call
 * InitialStateProvider::provide(), or this module throws on import.
 */
import { getCapabilities } from '@nextcloud/capabilities'
import { loadState } from '@nextcloud/initial-state'
import type { FileTypeFilterEntry } from './types/Search'

interface AppConfig {
  appId: string
  appName: string
  /** Admin-configured display name, or '' when unset — see productName(). */
  displayName: string
  /** True when the Files Preview app is installed and its bundle was loaded. */
  richPreview: boolean
  /** Extensions "Open in local app" hands to DoCoNEXT Bridge; admin-configurable. */
  bridgeExtensions: string[]
  /** The search page's "Type" filter categories, admin-configured order + selection. */
  fileTypeFilters: FileTypeFilterEntry[]
  /** True when DoCoNEXT Core can name entity types and entities to scope by. */
  coreScope: boolean
  /** True when this server has a full-text index, so files can be searched by content. */
  contentSearch: boolean
}

/** The slice of the server's capabilities DoCoNEXT Core publishes about itself. */
interface CoreCapability {
  doconext_core?: {
    /** The admin's name for Core, with Core's own default already applied. */
    productName?: string
  }
}

let config: AppConfig
try {
  config = loadState<AppConfig>('doconext_finder', 'config')
} catch {
  throw new Error(
    '[doconext_finder] Initial state missing — the PHP handler that '
		+ 'loaded this bundle must call InitialStateProvider::provide() first.',
  )
}

export const APP_ID = config.appId

/**
 * Whether the page loaded the Files Preview bundle. It registers a
 * <doconext-file-preview> custom element; without it the details panel falls
 * back to Nextcloud's own thumbnail.
 */
export const HAS_RICH_PREVIEW = config.richPreview === true

/**
 * Extensions that go to DoCoNEXT Bridge rather than to the Nextcloud desktop
 * client, lower-cased and without dots.
 *
 * Configured server-side because DoCoNEXT Core offers the same action: a rule
 * duplicated across two frontends is a rule that will disagree with itself.
 */
export const BRIDGE_EXTENSIONS: string[] = config.bridgeExtensions ?? []

/**
 * Which categories the search page's "Type" filter offers, and in what order.
 * Resolved against the builtin registry (label + mimetypes) by
 * src/filters/presets.ts — this array only says which ids to show.
 */
export const FILE_TYPE_FILTERS: FileTypeFilterEntry[] = config.fileTypeFilters ?? []

/**
 * Whether to offer the entity type and entity fields of the scope picker. False
 * means DoCoNEXT Core is absent; the folder field is always offered and does not
 * depend on this. Read from the initial state rather than from a request, so the
 * fields are either there on first paint or not at all.
 */
export const HAS_ENTITY_SCOPE = config.coreScope === true

/**
 * Whether the search box may offer to look inside files rather than only at
 * their names. False means this server has no full-text index; the name search
 * is always offered and does not depend on this.
 *
 * Read from the initial state for the same reason as HAS_ENTITY_SCOPE: a choice
 * that appears one round trip after the box it belongs to reads as a glitch.
 */
export const HAS_CONTENT_SEARCH = config.contentSearch === true

/**
 * The name DoCoNEXT Core goes by on this server, for "Open in <name>".
 *
 * From the capabilities every page already carries, which is the route Core
 * publishes it on precisely so integrating apps do not reach into its config:
 * Core is the single source of truth for its own name, and an admin renaming it
 * to "DMS" changes this label with it. The fallback only ever applies when Core
 * is absent, and then the command that uses it is not rendered anyway.
 *
 * A function rather than a constant: reading capabilities needs `window`, and
 * evaluating that at import time made this module unloadable anywhere without
 * one — which the test suite noticed before anyone else could.
 */
export function coreProductName(): string {
  try {
    return (getCapabilities() as CoreCapability)?.doconext_core?.productName || 'DoCoNEXT Core'
  } catch {
    return 'DoCoNEXT Core'
  }
}

export const API_BASE = `/apps/${APP_ID}/api`

const DEFAULT_PRODUCT_NAME = config.appName

/** Admin-configured display name, or '' when blank. Prefer productName(). */
export const DISPLAY_NAME = config.displayName ?? ''

/**
 * User-facing product label. The internal app id is never shown: either the
 * admin's custom name or the functional fallback (defaults to the app name).
 * @param fallback label to use when no custom display name is configured
 */
export function productName(fallback: string = DEFAULT_PRODUCT_NAME): string {
  return DISPLAY_NAME || fallback
}

export const PRODUCT_NAME = productName()
