import { afterEach, describe, expect, it, vi } from 'vitest'

/**
 * Column widths are read from this browser's storage once, when the module
 * loads, so each case gives it a fresh store and a fresh import.
 * @param stored what the store holds under the app's key, or null for nothing
 */
async function load(stored: string | null) {
	const items = new Map<string, string>()
	if (stored !== null) {
		items.set('doconext_finder-column-widths', stored)
	}
	vi.stubGlobal('window', {
		localStorage: {
			getItem: (key: string) => items.get(key) ?? null,
			setItem: (key: string, value: string) => items.set(key, value),
			removeItem: (key: string) => items.delete(key),
		},
	})
	vi.resetModules()
	const { useColumnWidths } = await import('../../src/composables/useColumnWidths')
	return { widths: useColumnWidths(), items }
}

afterEach(() => vi.unstubAllGlobals())

describe('useColumnWidths', () => {
	it('keeps a width that was dragged', async () => {
		const { widths } = await load('{"size":120,"name":300}')
		expect(widths.widthOf('size')).toBe(120)
		expect(widths.widthOf('name')).toBe(300)
		expect(widths.widthOf('folder')).toBeNull()
	})

	it('treats anything unusable as no width rather than guessing', async () => {
		const { widths } = await load('{"a":5,"b":90000,"c":"200","d":null,"e":150}')
		expect(['a', 'b', 'c', 'd'].map((id) => widths.widthOf(id))).toEqual([null, null, null, null])
		expect(widths.widthOf('e')).toBe(150)
	})

	it('survives a store that is not a map of widths', async () => {
		for (const stored of ['not json', '[120]', '42', 'null']) {
			const { widths } = await load(stored)
			expect(widths.widthOf('size')).toBeNull()
		}
	})

	it('writes a width and forgets it again', async () => {
		const { widths, items } = await load(null)
		widths.setWidth('size', 140)
		expect(JSON.parse(items.get('doconext_finder-column-widths') ?? '{}')).toEqual({ size: 140 })
		widths.setWidth('size', null)
		expect(items.has('doconext_finder-column-widths')).toBe(false)
	})
})
