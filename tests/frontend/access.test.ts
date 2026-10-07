import { describe, expect, it } from 'vitest'
import { canDownload, canRead } from '../../src/filters/access'

const noDownload = JSON.stringify([{ scope: 'permissions', key: 'download', value: false }])
const download = JSON.stringify([{ scope: 'permissions', key: 'download', value: true }])

describe('canRead', () => {
	it('follows the read bit', () => {
		expect(canRead({ permissions: 31 })).toBe(true)
		expect(canRead({ permissions: 1 })).toBe(true)
		expect(canRead({ permissions: 30 })).toBe(false)
		expect(canRead({ permissions: 0 })).toBe(false)
	})
})

describe('canDownload', () => {
	it('allows a readable file with no share attributes', () => {
		expect(canDownload({ permissions: 1, shareAttributes: null })).toBe(true)
	})

	it('refuses a file that cannot be read', () => {
		expect(canDownload({ permissions: 0, shareAttributes: download })).toBe(false)
	})

	it('follows a share that turned downloading off or on', () => {
		expect(canDownload({ permissions: 1, shareAttributes: noDownload })).toBe(false)
		expect(canDownload({ permissions: 1, shareAttributes: download })).toBe(true)
	})

	it('leaves unreadable attributes to the server', () => {
		expect(canDownload({ permissions: 1, shareAttributes: 'not json' })).toBe(true)
	})
})
