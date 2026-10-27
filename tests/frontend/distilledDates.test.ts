import { describe, expect, it } from 'vitest'
import { applyDistilled } from '../../src/filters/distilled'
import type { DistilledFileScope } from '../../src/types/Ai'
import type { FieldsResponse, MetadataField, SearchState } from '../../src/types/Search'

// A question about a business date — "judgments from autumn 2025 to spring
// 2026" — arrives as a range of days. Where the server can ask a field about
// days, the range is asked whole; elsewhere only a single year survives.
const t = (text: string) => text

const field = (dateKind: MetadataField['dateKind']): MetadataField => ({
	key: 'dcn_core_uitspraakdatum',
	field: 'meta:dcn_core_uitspraakdatum',
	label: 'Uitspraakdatum',
	labels: {},
	type: 'string',
	filterable: true,
	principal: null,
	dateKind,
})

const schema = (dateKind: MetadataField['dateKind'], dayOperators = true): FieldsResponse => ({
	fields: {},
	operators: {},
	sorts: [],
	maxLimit: 200,
	metadata: [field(dateKind)],
	metadataOperators: ['eq', 'contains'],
	...(dayOperators ? { metadataDateOperators: ['eq', 'lt', 'lte', 'gt', 'gte', 'contains'] } : {}),
})

const scope = (from: string | null, to: string | null): DistilledFileScope => ({
	mimetypes: [],
	mimeLabel: null,
	dateField: null,
	dateFieldKey: 'uitspraakdatum',
	dateFrom: from,
	dateTo: to,
	dateLabel: 'Uitspraak sept. 2025 – maart 2026',
	metadata: [],
	topic: null,
	chips: [],
})

const base = { term: '', conditions: [], matchAny: false } as unknown as SearchState

describe('a distilled range on a business date', () => {
	it('is asked as days where the field holds a date', () => {
		const applied = applyDistilled(t, scope('2025-09-01', '2026-03-31'), base, schema('date'), false)

		expect(applied.state.conditions.map((c) => [c.operator, c.value])).toEqual([['gte', '2025-09-01'], ['lte', '2026-03-31']])
		expect(applied.dropped).toEqual([])
	})

	it('keeps an open end open', () => {
		const applied = applyDistilled(t, scope('2026-01-01', null), base, schema('datetime'), false)

		expect(applied.state.conditions.map((c) => [c.operator, c.value])).toEqual([['gte', '2026-01-01']])
	})

	it('falls back to a year where the server cannot ask about days', () => {
		const applied = applyDistilled(t, scope('2026-01-01', '2026-12-31'), base, schema('date', false), false)

		expect(applied.state.conditions.map((c) => [c.operator, c.value])).toEqual([['contains', '2026']])
	})

	it('says it could not apply a range over two years there', () => {
		const applied = applyDistilled(t, scope('2025-09-01', '2026-03-31'), base, schema(null), false)

		expect(applied.state.conditions).toEqual([])
		expect(applied.dropped).toEqual(['Uitspraak sept. 2025 – maart 2026'])
	})
})
