import { afterEach, describe, expect, it, vi } from 'vitest'
import { canonicalModifiedPreset, modifiedPresets, modifiedRange } from '../../src/filters/presets'

// The date presets are the one place in this app doing calendar arithmetic, and
// the year boundaries are what a rolling-window implementation gets wrong: they
// must fall on the viewer's own 1 January, not 365 days back.
const t = (text: string, vars?: Record<string, unknown>) =>
	text.replace(/\{(\w+)\}/g, (_match, key: string) => String(vars?.[key] ?? ''))

const preset = (id: string) => {
	const found = modifiedPresets(t).find((p) => p.id === id)
	if (!found) {
		throw new Error(`no preset ${id}`)
	}
	return found
}

const unix = (date: Date) => Math.floor(date.getTime() / 1000)

afterEach(() => vi.useRealTimers())

describe('modifiedRange', () => {
	it('reaches back to the viewer’s own midnight for "Today"', () => {
		const midnight = new Date()
		midnight.setHours(0, 0, 0, 0)

		expect(modifiedRange(preset('today'))).toEqual({ after: unix(midnight), before: null })
	})

	it('starts "This month" on the 1st and leaves it open', () => {
		const today = new Date()

		expect(modifiedRange(preset('this-month'))).toEqual({
			after: unix(new Date(today.getFullYear(), today.getMonth(), 1)),
			before: null,
		})
	})

	it('closes "Last month" where this month begins', () => {
		const today = new Date()

		expect(modifiedRange(preset('last-month'))).toEqual({
			after: unix(new Date(today.getFullYear(), today.getMonth() - 1, 1)),
			before: unix(new Date(today.getFullYear(), today.getMonth(), 1)),
		})
	})

	it('lets "Last month" cross into the previous year in January', () => {
		vi.useFakeTimers()
		vi.setSystemTime(new Date(2026, 0, 15, 11, 30))

		expect(modifiedRange(preset('last-month'))).toEqual({
			after: unix(new Date(2025, 11, 1)),
			before: unix(new Date(2026, 0, 1)),
		})
	})

	it('starts "This year" on 1 January and leaves it open', () => {
		const year = new Date().getFullYear()

		expect(modifiedRange(preset('this-year'))).toEqual({
			after: unix(new Date(year, 0, 1)),
			before: null,
		})
	})

	it('closes "Last year" where the current year begins', () => {
		const year = new Date().getFullYear()

		expect(modifiedRange(preset('last-year'))).toEqual({
			after: unix(new Date(year - 1, 0, 1)),
			before: unix(new Date(year, 0, 1)),
		})
	})

	it('labels every preset without reading the clock', () => {
		// A label naming a year would go stale in a page left open across New
		// Year while the range under it moved on — so none of them names one.
		vi.useFakeTimers()
		vi.setSystemTime(new Date(2026, 11, 31, 23, 59))
		const labels = modifiedPresets(t).map((p) => p.label)

		vi.setSystemTime(new Date(2027, 0, 1, 0, 1))

		expect(modifiedPresets(t).map((p) => p.label)).toEqual(labels)
	})
})

describe('canonicalModifiedPreset', () => {
	// A stored search carrying the retired rolling-year id must land on a date
	// filter, not fall through to "Any time" and quietly widen itself.
	it('carries the retired rolling year over to the calendar one', () => {
		expect(canonicalModifiedPreset('year')).toBe('this-year')
	})

	it('leaves every current id alone', () => {
		for (const { id } of modifiedPresets(t)) {
			expect(canonicalModifiedPreset(id)).toBe(id)
		}
	})
})
