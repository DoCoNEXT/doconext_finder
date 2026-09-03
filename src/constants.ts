/**
 * App-wide constants, hydrated synchronously from the server-provided initial
 * state (InitialStateProvider::provide() → loadState). No network round-trip.
 *
 * Every PHP handler that loads one of the app's bundles MUST call
 * InitialStateProvider::provide(), or this module throws on import.
 */
import { loadState } from '@nextcloud/initial-state'

interface AppConfig {
	appId: string
	appName: string
	/** Admin-configured display name, or '' when unset — see productName(). */
	displayName: string
	/** True when the Files Preview app is installed and its bundle was loaded. */
	richPreview: boolean
	/** Extensions "Open in local app" hands to DoCoNEXT Bridge; admin-configurable. */
	bridgeExtensions: string[]
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
