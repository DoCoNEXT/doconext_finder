/** Shapes returned by the doconext_finder search endpoints. */

/** A condition operator, as named by GET /api/search/fields. */
export type Operator = 'eq' | 'lt' | 'lte' | 'gt' | 'gte' | 'contains'

export interface Condition {
  field: string
  operator: Operator
  value: string | number | boolean
  /** Not offered for join-backed fields; the server rejects those. */
  negate?: boolean
  /**
   * How the condition reads when its value alone does not say it — the business
   * date a distilled range came from. A display snapshot, like the scope's
   * label: it is kept with a stored search and never sent to the server.
   */
  label?: string
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
  /** The passage a content search matched, or '' — see SearchState.searchContent. */
  excerpt: string
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
  /**
   * True when the scope covered more folders than are worth querying and the
   * server searched only some of them. Results are real but incomplete.
   */
  truncated?: boolean
}

/**
 * The three levels a search can be scoped to, from widest to narrowest. They are
 * strictly nested, so a scope names one level — the deepest one chosen.
 *
 * `folder` works without DoCoNEXT Core and carries a plain Nextcloud file id;
 * the other three are Core's own hierarchy, and its own ids. A scope is one or
 * the other, never both.
 */
export type ScopeLevel = 'folder' | 'realm' | 'entityType' | 'entity'

/** A DoCoNEXT Core workspace, as offered by GET /api/scope. */
export interface ScopeRealm {
  id: number
  name: string
}

export interface ScopeEntityType {
  id: number
  /** Plural, as the picker lists it: "Huurovereenkomsten". */
  name: string
  /** What one of them is called; labels the entity box once this type is chosen. */
  singularName: string
  realmId: number
}

/** One typeahead suggestion. `context` is what tells two same-named ones apart. */
export interface ScopeEntity {
  id: number
  name: string
  context: string
}

/**
 * The Core entity a file sits in. `url` is Core's own page for it — built there,
 * because Core owns its routes.
 */
export interface ScopeFileEntity {
  id: number
  name: string
  code: string
  typeId: number
  typeName: string
  url: string
}

/** Empty lists mean DoCoNEXT Core is absent; the folder scope does not need it. */
export interface ScopeVocabulary {
  realms: ScopeRealm[]
  entityTypes: ScopeEntityType[]
}

/**
 * A chosen scope. `label` is a snapshot, not a lookup key: a saved search made
 * last year must still read as "Huurovereenkomsten" even after that type was
 * renamed or removed, and only the id is ever sent back to the server.
 */
export interface ScopeSelection {
  level: ScopeLevel
  id: number
  label: string
  /**
   * The workspace the scope lies in, where the picker knew it. Only for naming
   * metadata fields the way that workspace does; the server is never told.
   */
  realmId?: number
}

/** The server describes its own filterable surface, so menus aren't hardcoded. */
export interface MetadataField {
  /** Bare registry key, e.g. dcn_core_rechtsgebied. */
  key: string
  /** How the API addresses it, e.g. meta:dcn_core_rechtsgebied. */
  field: string
  /** What to call the field when no workspace says otherwise. */
  label: string
  /**
   * What each workspace calls it, by workspace id — DoCoNEXT Core labels its
   * fields per workspace. Empty without Core, and for any other app's keys.
   */
  labels: Record<number, string>
  type: string
  /** Only indexed keys can be filtered or sorted on. */
  filterable: boolean
  /**
   * What the field holds where it names people: the one kind of principal it
   * accepts — `user` or `group`, null where it takes any — and whether it can
   * name several at once. Null on every other field, and on all of them without
   * DoCoNEXT Core, which is the only app that knows.
   */
  principal: { type: string | null, multi: boolean } | null
}

/**
 * Someone a principal field names, as the picker offers them.
 *
 * A file stores the bare `id`; the name is looked up for showing, and travels
 * with a stored search as the condition's label so a restored filter still
 * reads as a person rather than as an account id.
 */
export interface Principal {
  /** 'user', 'group', or 'circle' — a Nextcloud Team. */
  type: string
  id: string
  displayName: string
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
  /**
   * Matched against the file's text by the full-text index, as an *alternative*
   * to `term` rather than a further narrowing of it. Still a field of its own on
   * the wire because the two are answered by different engines; the interface
   * fills it with the term itself — see SearchState.searchContent.
   */
  content?: string
  conditions?: Condition[]
  /**
   * Preset filters. Kept apart from `conditions` because they AND with
   * everything: "match any" must not widen the chosen type or date range.
   */
  mimetypes?: string[]
  /** Unix seconds; only files modified after this. */
  modifiedAfter?: number
  /**
   * Unix seconds; only files modified before this. Sent only by a preset that
   * names a period that has ended ("Last year"), where an open-ended cutoff
   * would reach forward into today.
   */
  modifiedBefore?: number
  /** Where the search starts. Not a condition — see FileScope on the server. */
  scope?: { level: ScopeLevel, id: number }
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
  /**
   * Whether the term is looked for inside the files as well as in their names.
   *
   * Widens the search rather than narrowing it: a file matches when its name
   * matches or its text does. It is a toggle rather than a second box because
   * there is one question — "find me the lease" — and asking it twice, once per
   * field, was the part nobody could make sense of. Only offered where the
   * server has a full-text index; see HAS_CONTENT_SEARCH.
   */
  searchContent: boolean
  typePreset: string
  /**
   * A file type the distiller chose that no configured Type filter matches —
   * "Word document", say, where this server only offers the broader
   * "Documents". Carried as a whole preset so it can sit in the Type dropdown
   * as an ordinary option, editable and visible, rather than as a hidden
   * filter. Null whenever `typePreset` names a real one.
   */
  customType: { id: string, label: string, mimetypes: string[] } | null
  modifiedPreset: string
  conditions: Condition[]
  matchAny: boolean
  sort: string
  descending: boolean
  /** null when the search is not scoped. Rides along in the stored query JSON. */
  scope: ScopeSelection | null
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
  /**
   * Whether the details panel is on screen. A view option, not a live state: it
   * stays on until you close the panel, and while it is off a click selects a
   * row without a panel appearing.
   */
  sidebarPinned: boolean
  /**
   * The colour the searched-for words are marked in inside a preview, as
   * `#rrggbb`. Empty means the preview app's own themed default — this app does
   * not draw the mark, so it has nothing better to put there.
   */
  highlightColor: string
}
