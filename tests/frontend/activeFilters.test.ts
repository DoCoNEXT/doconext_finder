import { describe, expect, it } from 'vitest'
import { activeFilters } from '../../src/filters/describe'
import type { SearchState } from '../../src/types/Search'

// The filters in force are what the search page counts on its Filters button
// and lists as chips. Each must be something the search actually does: a chip
// for a filter that is not sent would offer to remove nothing.
const t = (text: string, vars?: Record<string, unknown>) =>
	text.replace(/\{(\w+)\}/g, (_match, key: string) => String(vars?.[key] ?? ''))

const query = (overrides: Partial<SearchState>): SearchState => ({
	term: '', searchContent: false, typePresets: [], customType: null, modifiedPreset: 'any',
	conditions: [], matchAny: false, scope: null, sort: 'mtime', descending: true,
	...overrides,
})

describe('activeFilters', () => {
	it('finds none in a search that is only a term', () => {
		expect(activeFilters(t, query({ term: 'quote', searchContent: true }))).toEqual([])
	})

	it('lists scope, period and conditions in the order the summary names them', () => {
		const filters = activeFilters(t, query({
			scope: { level: 'folder', id: 7, label: 'Projects' },
			modifiedPreset: 'this-month',
			conditions: [{ field: 'name', operator: 'contains', value: 'quote' }],
		}))

		expect(filters.map((f) => f.kind)).toEqual(['scope', 'time', 'condition'])
		expect(filters[0].label).toBe('In Projects')
	})

	it('leaves out a condition row that has no value yet, and keeps the others at their row', () => {
		const filters = activeFilters(t, query({
			conditions: [
				{ field: 'name', operator: 'contains', value: '' },
				{ field: 'name', operator: 'contains', value: 'plan' },
			],
		}))

		expect(filters).toHaveLength(1)
		expect(filters[0]).toMatchObject({ kind: 'condition', index: 1, key: 'condition-1' })
	})

	it('counts a distilled type that the configured list has no name for', () => {
		const filters = activeFilters(t, query({
			typePresets: ['distilled-cad'],
			customType: { id: 'distilled-cad', label: 'CAD drawings', mimetypes: ['application/acad'] },
		}))

		expect(filters).toEqual([{ key: 'type-distilled-cad', kind: 'type', id: 'distilled-cad', label: 'CAD drawings' }])
	})

	it('gives each picked type a chip of its own, so one can go without the others', () => {
		const filters = activeFilters(t, query({
			typePresets: ['distilled-cad', 'distilled-dwg'],
			customType: { id: 'distilled-cad', label: 'CAD drawings', mimetypes: ['application/acad'] },
		}))

		// The second id is known to nothing, so it is not a filter in force.
		expect(filters.map((f) => f.id)).toEqual(['distilled-cad'])
	})
})
