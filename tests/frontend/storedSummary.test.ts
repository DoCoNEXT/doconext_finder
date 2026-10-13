import { describe, expect, it } from 'vitest'
import { describeStored } from '../../src/filters/describe'
import type { SearchState, StoredSearch } from '../../src/types/Search'

// A listed search shows a title and a summary line under it. A recent search is
// titled by its term; a saved one by its name — so for a saved search the term
// has to be in the summary, or "Quotes this year · This year" reads as every
// file from this year.
const t = (text: string, vars?: Record<string, unknown>) =>
	text.replace(/\{(\w+)\}/g, (_match, key: string) => String(vars?.[key] ?? ''))

const entry = (name: string | null, overrides: Partial<SearchState>): StoredSearch => ({
	id: 1,
	kind: name === null ? 'recent' : 'saved',
	name,
	description: null,
	lastRun: 0,
	query: {
		term: '', searchContent: false, typePreset: 'any', customType: null, modifiedPreset: 'any',
		conditions: [], matchAny: false, scope: null, sort: 'mtime', descending: true,
		...overrides,
	},
})

describe('describeStored', () => {
	it('puts the term in front when the title is a name', () => {
		expect(describeStored(t, entry('Quotes this year', { term: 'quote', modifiedPreset: 'this-year' })))
			.toBe('“quote” · This year')
	})

	it('names only the term when there is nothing else to say', () => {
		expect(describeStored(t, entry('Quotes', { term: 'quote' }))).toBe('“quote”')
	})

	it('leaves the term out of a recent search, whose title already is the term', () => {
		expect(describeStored(t, entry(null, { term: 'quote', modifiedPreset: 'this-year' })))
			.toBe('This year')
	})

	it('does not repeat a name that is just the term', () => {
		expect(describeStored(t, entry(' Quote ', { term: 'quote' }))).toBe('')
	})

	it('says nothing about a term a saved search does not have', () => {
		expect(describeStored(t, entry('Changed last week', { modifiedPreset: 'week' }))).toBe('Last 7 days')
	})
})
