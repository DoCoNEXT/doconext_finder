/** Shapes returned by the doconext_finder search endpoints. */

/** A condition operator, as named by GET /api/search/fields. */
export type Operator = 'eq' | 'lt' | 'lte' | 'gt' | 'gte' | 'contains'

export interface Condition {
	field: string
	operator: Operator
	value: string | number | boolean
	/** Not offered for join-backed fields; the server rejects those. */
	negate?: boolean
}

export interface FileResult {
	fileid: number
	name: string
	/** Path relative to the user's files root, e.g. Legal/Dossiers/case.pdf */
	path: string
	mimetype: string
	isFolder: boolean
	size: number
	/** Unix seconds. */
	mtime: number
	creationTime: number
	permissions: number
	/** Mutated optimistically by the star toggle. */
	favorite: boolean
}

export interface SearchResponse {
	results: FileResult[]
	/** True when another page exists — the backend returns no total count. */
	hasMore: boolean
	offset: number
	limit: number
}

/** The server describes its own filterable surface, so menus aren't hardcoded. */
export interface FieldsResponse {
	/** field name → value type ('string' | 'integer' | 'boolean') */
	fields: Record<string, string>
	/** field name → the operators that field actually accepts */
	operators: Record<string, Operator[]>
	sorts: string[]
	maxLimit: number
}

export interface SearchRequest {
	term?: string
	conditions?: Condition[]
	/**
	 * Preset filters. Kept apart from `conditions` because they AND with
	 * everything: "match any" must not widen the chosen type or date range.
	 */
	mimetypes?: string[]
	/** Unix seconds; only files modified after this. */
	modifiedAfter?: number
	matchAny?: boolean
	limit?: number
	offset?: number
	sort?: string
	descending?: boolean
}
