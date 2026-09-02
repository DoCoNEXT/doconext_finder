// Stub for @nextcloud/initial-state in unit tests — the real one reads the
// page <head>, which doesn't exist in a node run. Returns the app's bootstrap
// config so importing src/constants.ts doesn't throw.
export function loadState(_app: string, _key: string): unknown {
	return {
		appId: 'doconext_finder',
		appName: 'DoCoNEXT Finder',
		displayName: '',
	}
}
