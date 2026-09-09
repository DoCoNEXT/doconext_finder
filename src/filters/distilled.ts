/**
 * Turns what Core's distiller understood into this app's own search state.
 *
 * Not a one-to-one copy. Core hands back the pieces a WebDAV DASL query is
 * built from — mimetype patterns, an ISO date range, metadata equalities — and
 * this app searches the filecache through a validated FileQuery instead. Dates
 * become unix seconds, metadata keys gain their prefixes, and anything this
 * server cannot actually filter on is dropped.
 *
 * Dropped *visibly*. A distilled scope is a claim about what the question meant,
 * and silently ignoring half of it would leave someone looking at results that
 * do not answer what they asked. Every function here reports what it could not
 * apply so the interface can say so.
 */
import { fileTypePresets } from './presets'
import type { Translate } from './presets'
import type { DistilledFileScope } from '../types/Ai'
import type { Condition, FieldsResponse, SearchState } from '../types/Search'

/** Core writes its registry keys into file metadata under this prefix. */
const CORE_KEY_PREFIX = 'dcn_core_'

/** How this app addresses a metadata field in a condition. */
const META_PREFIX = 'meta:'

/**
 * One thing the distiller understood, and enough to undo it.
 *
 * Not a label: a chip nobody can remove is a claim rather than a control, and
 * the piece it names is buried in a condition list the user did not write. The
 * `kind` and its coordinates say which part of the query to clear, and let the
 * chip disappear on its own once that part is gone — cleared here, edited in
 * the filters below, or wiped by "New search".
 */
export interface ScopeChip {
  label: string
  kind: 'type' | 'condition' | 'topic'
  /** For a condition chip: which one, matched by value rather than by index. */
  field?: string
  value?: string | number | boolean
}

export interface AppliedScope {
  /** The state to run, ready to hand to the store. */
  state: SearchState
  /** What was understood, each removable on its own. */
  chips: ScopeChip[]
  /** Human-readable reasons, one per piece that could not be applied. */
  dropped: string[]
}

/**
 * @param t translation function
 * @param scope what Core understood
 * @param base the state to build on — the current one, so the user's scope survives
 * @param schema this server's filterable surface, or null when not loaded yet
 * @param contentSearch whether the topic can become a content search
 */
export function applyDistilled(
  t: Translate,
  scope: DistilledFileScope,
  base: SearchState,
  schema: FieldsResponse | null,
  contentSearch: boolean,
): AppliedScope {
  const dropped: string[] = []
  const chips: ScopeChip[] = []
  const conditions: Condition[] = []

  const state: SearchState = {
    ...base,
    term: '',
    content: '',
    typePreset: 'any',
    customType: null,
    modifiedPreset: 'any',
    conditions,
    // A distilled scope is a conjunction: every piece narrows the question the
    // user asked. Whatever "match any" was set to before described a different
    // search entirely.
    matchAny: false,
  }

  applyType(t, scope, state, chips)
  applyDates(scope, conditions, chips, dropped)
  applyMetadata(scope, schema, conditions, chips, dropped)
  applyTopic(scope, state, contentSearch, chips, dropped)

  return { state, chips, dropped }
}

/**
 * The file type.
 *
 * An exact match against a configured Type filter is preferred, so the dropdown
 * shows the name this server already uses. Failing that the distilled type is
 * carried as a filter of its own rather than dropped: the admin's list is
 * curated and coarse — "Documents" covering everything from PDF to Markdown —
 * while a question can be as precise as "Word document", and answering a
 * precise question with a broader filter searches more than was asked.
 */
const DISTILLED_TYPE_ID = 'distilled'

function applyType(t: Translate, scope: DistilledFileScope, state: SearchState, chips: ScopeChip[]): void {
  if (scope.mimetypes.length === 0) {
    return
  }

  const wanted = [...scope.mimetypes].sort().join('|')
  const preset = fileTypePresets(t).find(
    (p) => p.id !== 'any' && [...p.mimetypes].sort().join('|') === wanted,
  )

  if (preset) {
    state.typePreset = preset.id
    chips.push({ label: preset.label, kind: 'type' })

    return
  }

  const label = scope.mimeLabel || scope.mimetypes.join(', ')
  state.typePreset = DISTILLED_TYPE_ID
  state.customType = { id: DISTILLED_TYPE_ID, label, mimetypes: scope.mimetypes }
  chips.push({ label, kind: 'type' })
}

/**
 * The date range, as conditions rather than as a preset: the presets are
 * rolling windows ("last 7 days") and a question like "from 2025" is an
 * absolute range, which only a condition can express.
 */
function applyDates(
  scope: DistilledFileScope,
  conditions: Condition[],
  chips: ScopeChip[],
  dropped: string[],
): void {
  if (scope.dateFieldKey !== null) {
    applyMetadataDate(scope, conditions, chips, dropped)

    return
  }

  if (scope.dateField === null) {
    return
  }

  const field = scope.dateField === 'created' ? 'creation_time' : 'mtime'
  const from = startOfDay(scope.dateFrom)
  const to = endOfDay(scope.dateTo)

  if (from !== null) {
    conditions.push({ field, operator: 'gte', value: from, label: scope.dateLabel ?? undefined })
  }
  if (to !== null) {
    conditions.push({ field, operator: 'lte', value: to, label: scope.dateLabel ?? undefined })
  }

  // One chip for the range, keyed on its opening bound: removing it takes both
  // ends, because half a range is not a filter anyone asked for.
  if (from !== null || to !== null) {
    chips.push({
      label: scope.dateLabel ?? '',
      kind: 'condition',
      field,
      value: from ?? to ?? undefined,
    })
  }
}

/**
 * A business date — a judgment date, a publication date — rather than the
 * file's own.
 *
 * Metadata lives in one indexed string column, so it compares as text: no
 * greater-than, no range. A year is therefore matched as a substring, which is
 * exactly what the desktop client does and works because these values are
 * written ISO-first. A range that is not a whole single year cannot be
 * expressed at all, and says so.
 */
function applyMetadataDate(
  scope: DistilledFileScope,
  conditions: Condition[],
  chips: ScopeChip[],
  dropped: string[],
): void {
  const year = sameYear(scope.dateFrom, scope.dateTo)

  if (year === null) {
    dropped.push(scope.dateLabel ?? '')

    return
  }

  const field = META_PREFIX + CORE_KEY_PREFIX + scope.dateFieldKey
  conditions.push({ field, operator: 'contains', value: year, label: scope.dateLabel ?? undefined })
  chips.push({ label: scope.dateLabel ?? year, kind: 'condition', field, value: year })
}

/**
 * The metadata equalities.
 *
 * Core decides "searchable" from its own field flag; this app decides
 * "filterable" from Nextcloud's metadata index. The two can disagree — a field
 * Core is happy to distil against may never have been indexed here — so every
 * condition is checked against this server's own schema before it is used.
 */
function applyMetadata(
  scope: DistilledFileScope,
  schema: FieldsResponse | null,
  conditions: Condition[],
  chips: ScopeChip[],
  dropped: string[],
): void {
  for (const meta of scope.metadata) {
    const key = CORE_KEY_PREFIX + meta.fieldKey
    const known = schema?.metadata.find((f) => f.key === key)

    if (schema !== null && (known === undefined || !known.filterable)) {
      dropped.push(`${meta.fieldLabel}: ${meta.valueLabel}`)
      continue
    }

    conditions.push({
      field: META_PREFIX + key,
      operator: 'eq',
      value: meta.value,
      label: meta.valueLabel,
    })
    chips.push({
      label: `${meta.fieldLabel}: ${meta.valueLabel}`,
      kind: 'condition',
      field: META_PREFIX + key,
      value: meta.value,
    })
  }
}

/**
 * The subject itself — everything the distiller could not turn into a filter.
 *
 * It goes to the content search, which is where it was always meant to go: as a
 * filename filter it ANDs a good scope down to nothing whenever the word is not
 * in the names, which is why it used to be offered as an opt-in chip. Without a
 * full-text index there is still nowhere safe to put it, so it is reported
 * instead of guessed at.
 */
function applyTopic(
  scope: DistilledFileScope,
  state: SearchState,
  contentSearch: boolean,
  chips: ScopeChip[],
  dropped: string[],
): void {
  const topic = scope.topic?.trim() ?? ''
  if (topic === '') {
    return
  }

  if (!contentSearch) {
    dropped.push(`“${topic}”`)
    return
  }

  state.content = topic
  state.sort = 'relevance'
  state.descending = true
  chips.push({ label: `“${topic}”`, kind: 'topic' })
}

/** ISO date → unix seconds at the start of that day, in the viewer's timezone. */
function startOfDay(iso: string | null): number | null {
  const date = parseIso(iso)
  if (date === null) {
    return null
  }
  date.setHours(0, 0, 0, 0)

  return Math.floor(date.getTime() / 1000)
}

/**
 * ISO date → unix seconds at the *end* of that day. "Until 31 December" means
 * the whole of the 31st; cutting at its midnight would lose a day's files.
 */
function endOfDay(iso: string | null): number | null {
  const date = parseIso(iso)
  if (date === null) {
    return null
  }
  date.setHours(23, 59, 59, 0)

  return Math.floor(date.getTime() / 1000)
}

function parseIso(iso: string | null): Date | null {
  if (!iso) {
    return null
  }
  const [year, month, day] = iso.split('-').map(Number)
  if (!year || !month || !day) {
    return null
  }

  return new Date(year, month - 1, day)
}

/** The year both ends of a range fall in, or null when they do not. */
function sameYear(from: string | null, to: string | null): string | null {
  const years = [from, to]
    .filter((v): v is string => typeof v === 'string' && v.length >= 4)
    .map((v) => v.slice(0, 4))

  const first = years[0]
  if (first === undefined || years.some((y) => y !== first)) {
    return null
  }

  return first
}
