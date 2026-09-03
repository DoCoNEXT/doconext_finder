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
	/** uid of the file's owner. */
	owner: string
	/** Display name of the owner — the nearest thing Nextcloud keeps to a creator. */
	createdBy: string
	/** Display name of whoever wrote the current revision; the owner when unrecorded. */
	modifiedBy: string
	/** Registry key → displayable value, for keys this file actually carries. */
	metadata: Record<string, string>
}

export interface SearchResponse {
	results: FileResult[]
	/** True when another page exists — the backend returns no total count. */
	hasMore: boolean
	offset: number
	limit: number
}

/** The server describes its own filterable surface, so menus aren't hardcoded. */
export interface MetadataField {
	/** Bare registry key, e.g. dcn_core_rechtsgebied. */
	key: string
	/** How the API addresses it, e.g. meta:dcn_core_rechtsgebied. */
	field: string
	label: string
	type: string
	/** Only indexed keys can be filtered or sorted on. */
	filterable: boolean
}

export interface FieldsResponse {
	/** field name → value type ('string' | 'integer' | 'boolean') */
	fields: Record<string, string>
	/** field name → the operators that field actually accepts */
	operators: Record<string, Operator[]>
	sorts: string[]
	maxLimit: number
	/** Whatever this server's apps registered — Core's fields appear here. */
	metadata: MetadataField[]
	metadataOperators: Operator[]
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

/**
 * The interface state that reproduces a search — what saved searches and recents
 * store. Deliberately holds preset *ids* rather than the values they resolve to:
 * storing the timestamp behind "Last 7 days" would freeze it to the week it was
 * saved.
 */
export interface SearchState {
  term: string
  typePreset: string
  modifiedPreset: string
  conditions: Condition[]
  matchAny: boolean
  sort: string
  descending: boolean
}

export interface StoredSearch {
  id: number
  kind: 'saved' | 'recent'
  name: string | null
  description: string | null
  query: SearchState
  /** Unix seconds. */
  lastRun: number
}

export interface SearchHistory {
  saved: StoredSearch[]
  recents: StoredSearch[]
}

/** A column in the result grid. `label` empty means "use the built-in name". */
export interface ColumnPref {
  id: string
  visible: boolean
  label: string
}

/** The result lists that keep their own view options. */
export type GroupScope = 'search' | 'favorites'

/** Admin-configured "Type" filter entry — see GET config.fileTypeFilters. */
export interface BuiltinFileTypeFilterEntry {
	type: 'builtin'
	/** One of the ids src/filters/presets.ts defines a label + mimetype list for. */
	id: string
}

/** Admin-authored, shown as typed — never passed through translation. */
export interface CustomFileTypeFilterEntry {
	type: 'custom'
	id: string
	label: string
	mimetypes: string[]
}

export type FileTypeFilterEntry = BuiltinFileTypeFilterEntry | CustomFileTypeFilterEntry

export interface Preferences {
  columns: ColumnPref[]
  /** Ordered grouping levels for the search results, outermost first. */
  grouping: string[]
  /** The same, for the favorites list — a separate page, so separate levels. */
  favoritesGrouping: string[]
  pageSize: number
  sort: string
  descending: boolean
  /** What a double-click on a row does: open | folder | none. */
  doubleClick: string
  /** Keeps the details panel open while you move through the results. */
  sidebarPinned: boolean
}
