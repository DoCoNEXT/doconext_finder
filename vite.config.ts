import { createAppConfig } from '@nextcloud/vite-config'
import { join, resolve } from 'path'

/**
 * One entry per built bundle. `main` is the app page; `admin-settings` /
 * `personal-settings` are the settings panels. Add an entry here for each new
 * addScript site. Output goes to js/ + css/ (served by Nextcloud).
 */
export default createAppConfig(
	{
		main: resolve(join('src', 'main.ts')),
		'admin-settings': resolve(join('src', 'admin-settings.ts')),
		'personal-settings': resolve(join('src', 'personal-settings.ts')),
	},
	{
		createEmptyCSSEntryPoints: true,
		extractLicenseInformation: true,
		thirdPartyLicense: false,
	},
)
