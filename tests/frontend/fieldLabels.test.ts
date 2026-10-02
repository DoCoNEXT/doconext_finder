import { describe, expect, it } from 'vitest'
import { describeQuery } from '../../src/filters/describe'
import { labelledFor, realmOfScope } from '../../src/filters/metadata'
import type { MetadataField, SearchState } from '../../src/types/Search'

// A metadata field is stored under a key and called something else: an English
// workspace calls `dcn_core_dossiernummer` "Matter Number". Every place that
// names a field — the summary line included — says the label, in the words of
// the workspace the search is narrowed to where it is narrowed to one.
const t = (text: string, vars?: Record<string, unknown>) =>
	text.replace(/\{(\w+)\}/g, (_match, key: string) => String(vars?.[key] ?? ''))

const field = (key: string, label: string, labels: Record<number, string> = {}): MetadataField => ({
	key, field: `meta:${key}`, label, labels, type: 'string', filterable: true, principal: null,
})

const registry = [
	field('dcn_core_dossiernummer', 'Matter Number', { 1: 'Matter Number' }),
	field('dcn_core_document_type', 'Document type', { 1: 'Type of record', 3: 'Kind of document' }),
]

const query = (overrides: Partial<SearchState>): SearchState => ({
	term: '', searchContent: false, typePreset: 'any', modifiedPreset: 'any',
	conditions: [], matchAny: false, scope: null, sort: 'name', descending: false,
	...overrides,
} as SearchState)

describe('describeQuery', () => {
	it('names a metadata condition by its label, not by the id it is sent under', () => {
		const line = describeQuery(t, query({
			conditions: [{ field: 'meta:dcn_core_dossiernummer', operator: 'eq', value: 'MAT00001' }],
		}), registry)

		expect(line).toContain('Matter Number')
		expect(line).not.toContain('meta:')
	})

	it('uses the label of the workspace the search is narrowed to', () => {
		const line = describeQuery(t, query({
			scope: { level: 'realm', id: 3, label: 'HR' },
			conditions: [{ field: 'meta:dcn_core_document_type', operator: 'eq', value: 'Contract' }],
		}), registry)

		expect(line).toContain('Kind of document')
	})

	it('falls back to the bare key for a field the registry does not know', () => {
		const line = describeQuery(t, query({
			conditions: [{ field: 'meta:other_key', operator: 'eq', value: 'x' }],
		}))

		expect(line).toContain('other_key')
		expect(line).not.toContain('meta:')
	})
})

describe('labelledFor', () => {
	it('keeps the general label without a workspace', () => {
		expect(labelledFor(registry, null)[1].label).toBe('Document type')
	})

	it('keeps the general label for a field the workspace does not have', () => {
		expect(labelledFor(registry, 3)[0].label).toBe('Matter Number')
	})
})

describe('realmOfScope', () => {
	it('is the scope itself for a workspace, and what the picker recorded for anything deeper', () => {
		expect(realmOfScope({ level: 'realm', id: 4, label: '' })).toBe(4)
		expect(realmOfScope({ level: 'entityType', id: 9, label: '', realmId: 2 })).toBe(2)
		expect(realmOfScope({ level: 'folder', id: 9, label: '' })).toBeNull()
		expect(realmOfScope(null)).toBeNull()
	})
})
