/**
 * A one-line summary of a stored search, for the saved/recent lists, and the
 * filters in force, for the search page's chips.
 *
 * Reads the interface state rather than the request, so it can name the preset
 * the user picked ("Last 7 days") instead of the timestamp it resolves to.
 */
import { fieldLabel, isUsable, operatorLabel, toDateInput, toMegabytes } from './fields'
import { canonicalModifiedPreset, fileTypePresets, modifiedPresets } from './presets'
import type { FileTypePreset, ModifiedPreset, Translate } from './presets'
import { isMetadataField, labelledFor, metadataKeyOf, realmOfScope } from './metadata'
import type { Condition, MetadataField, SearchState, StoredSearch } from '../types/Search'

/**
 * @param t translation function
 * @param query the search, as the interface holds it
 * @param metadata the server's metadata registry, so a metadata condition is
 *   named the way its field is rather than by the id it is sent under
 */
/**
 * Where a scoped search looks. A search shared with someone who cannot open
 * its folder arrives with the folder's name withheld; it still says that it
 * looks somewhere, so it does not read as a search of everything.
 * @param t translation function
 * @param query a search with a scope
 */
function scopePhrase(t: Translate, query: SearchState): string {
  const label = query.scope?.label ?? ''
  return label === '' ? t('In a place you cannot open') : t('In {name}', { name: label })
}

export function describeQuery(t: Translate, query: SearchState, metadata: MetadataField[] = []): string {
  const parts: string[] = []

  // The scope goes first: it says where the search looked, which qualifies
  // everything after it. Its label is the snapshot stored with the search, so a
  // folder or entity type that has since been renamed still reads as it did.
  if (query.scope) {
    parts.push(scopePhrase(t, query))
  }

  // The term itself is not described here — wherever a search is listed, its
  // term is the title or {@link describeStored} puts it in front — but how wide
  // its net is, is not visible anywhere else, and a saved search that reads the
  // same whether it looked inside the files or not is two searches under one
  // label.
  if (query.searchContent) {
    parts.push(t('Contents too'))
  }

  const type = typeOf(t, query)
  if (type) {
    parts.push(type.label)
  }

  const time = timeOf(t, query)
  if (time) {
    parts.push(time.label)
  }

  const fields = labelledFor(metadata, realmOfScope(query.scope))
  const conditions = (query.conditions ?? []).map((c) => describeCondition(t, c, fields))
  if (conditions.length > 0) {
    // "any" conditions are alternatives to each other; joining them with the
    // same separator as the AND-ed presets would misrepresent the search.
    parts.push(query.matchAny && conditions.length > 1
      ? `(${conditions.join(t(' or '))})`
      : conditions.join(' · '))
  }

  return parts.join(' · ')
}

/** One filter in force, as the search page lists it: a chip that clears it. */
export interface ActiveFilter {
  key: string
  kind: 'scope' | 'type' | 'time' | 'condition'
  label: string
  /** Which row of `query.conditions`, for a condition. */
  index?: number
}

/**
 * The filters narrowing a search, one entry each, in the order
 * {@link describeQuery} names them.
 *
 * Neither the term nor the contents switch is one: both stay on screen whether
 * the filters are folded or not. A condition row without a value is left out,
 * since the search does not send it either — a chip for it would claim a
 * filter that does nothing.
 *
 * @param t translation function
 * @param query the search, as the interface holds it
 * @param metadata see {@link describeQuery}
 */
export function activeFilters(t: Translate, query: SearchState, metadata: MetadataField[] = []): ActiveFilter[] {
  const filters: ActiveFilter[] = []

  if (query.scope) {
    filters.push({ key: 'scope', kind: 'scope', label: scopePhrase(t, query) })
  }

  const type = typeOf(t, query)
  if (type) {
    filters.push({ key: 'type', kind: 'type', label: type.label })
  }

  const time = timeOf(t, query)
  if (time) {
    filters.push({ key: 'time', kind: 'time', label: time.label })
  }

  const fields = labelledFor(metadata, realmOfScope(query.scope))
  ;(query.conditions ?? []).forEach((condition, index) => {
    if (isUsable(condition)) {
      filters.push({ key: `condition-${index}`, kind: 'condition', index, label: describeCondition(t, condition, fields) })
    }
  })

  return filters
}

/**
 * The Type filter in force, or null for "any type". A distilled type that is
 * not in the configured list is still a type in force.
 * @param t translation function
 * @param query the search
 */
function typeOf(t: Translate, query: SearchState): FileTypePreset | null {
  const type = query.customType?.id === query.typePreset
    ? query.customType
    : fileTypePresets(t).find((p) => p.id === query.typePreset)

  return type && type.id !== 'any' ? type : null
}

/**
 * The Modified preset in force, or null for "any time".
 * @param t translation function
 * @param query the search
 */
function timeOf(t: Translate, query: SearchState): ModifiedPreset | null {
  const time = modifiedPresets(t).find((p) => p.id === canonicalModifiedPreset(query.modifiedPreset))

  return time && time.id !== 'any' ? time : null
}

/**
 * The summary line of a listed search: {@link describeQuery}, plus the term
 * when the title does not already show it.
 *
 * A recent search is titled by its term, but a saved one by its name, and
 * without this its term appeared nowhere: "Quotes this year · This year" read
 * as every file from this year, though it only finds the ones named "quote".
 * A name that merely repeats the term — what saving a recent search gives —
 * is left as it is rather than saying the same word twice.
 *
 * @param t translation function
 * @param entry the saved or recent search
 * @param metadata see {@link describeQuery}
 */
export function describeStored(t: Translate, entry: StoredSearch, metadata: MetadataField[] = []): string {
  const term = entry.query.term?.trim() ?? ''
  const name = entry.name?.trim() ?? ''
  const titledByTerm = name === '' || name.toLocaleLowerCase() === term.toLocaleLowerCase()

  return [
    term !== '' && !titledByTerm ? t('“{term}”', { term }) : '',
    describeQuery(t, entry.query, metadata),
  ].filter((part) => part !== '').join(' · ')
}

function describeCondition(t: Translate, condition: Condition, metadata: MetadataField[]): string {
  const field = isMetadataField(condition.field)
    ? metadata.find((f) => f.field === condition.field)?.label ?? metadataKeyOf(condition.field)
    : fieldLabel(t, condition.field)
  const operator = operatorLabel(t, condition.field, condition.operator)

  if (condition.field === 'favorite') {
    return t('Favorite')
  }

  // The label, where there is one, is the name behind an id — "Jan Sanders",
  // not "jsanders".
  const value = condition.label
    ?? (condition.field === 'size'
      ? `${toMegabytes(condition.value as number)} MB`
      : ['mtime', 'creation_time'].includes(condition.field)
        ? toDateInput(condition.value as number)
        : String(condition.value))

  return `${condition.negate ? t('not ') : ''}${field} ${operator} ${value}`
}
