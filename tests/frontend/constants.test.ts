import { describe, expect, it } from 'vitest'
import { productName } from '../../src/constants'

// Example frontend unit test. @nextcloud/initial-state + l10n are stubbed via
// vitest.config.ts so importing src/constants.ts works in a node run.
describe('productName', () => {
	it('falls back to the app name when no display name is configured', () => {
		expect(productName()).toBe('DoCoNEXT Finder')
	})

	it('uses the provided fallback', () => {
		expect(productName('Custom label')).toBe('Custom label')
	})
})
