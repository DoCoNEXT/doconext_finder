/**
 * A one-line summary of a stored search, for the saved/recent lists.
 *
 * Reads the interface state rather than the request, so it can name the preset
 * the user picked ("Last 7 days") instead of the timestamp it resolves to.
 */
import { fieldLabel, operatorLabel, toDateInput, toMegabytes } from './fields'
import { canonicalModifiedPreset, fileTypePresets, modifiedPresets } from './presets'
import type { Translate } from './presets'
import type { Condition, SearchState } from '../types/Search'

export function describeQuery(t: Translate, query: SearchState): string {
  const parts: string[] = []

  // The scope goes first: it says where the search looked, which qualifies
  // everything after it. Its label is the snapshot stored with the search, so a
  // folder or entity type that has since been renamed still reads as it did.
  if (query.scope) {
    parts.push(t('In {name}', { name: query.scope.label }))
  }

  // The term itself is not described — the box shows it — but how wide its net
  // is, is not visible anywhere else, and a saved search that reads the same
  // whether it looked inside the files or not is two searches under one label.
  if (query.searchContent) {
    parts.push(t('Contents too'))
  }

  const type = query.customType?.id === query.typePreset
    ? query.customType
    : fileTypePresets(t).find((p) => p.id === query.typePreset)
  if (type && type.id !== 'any') {
    parts.push(type.label)
  }

  const time = modifiedPresets(t).find((p) => p.id === canonicalModifiedPreset(query.modifiedPreset))
  if (time && time.id !== 'any') {
    parts.push(time.label)
  }

  const conditions = (query.conditions ?? []).map((c) => describeCondition(t, c))
  if (conditions.length > 0) {
    // "any" conditions are alternatives to each other; joining them with the
    // same separator as the AND-ed presets would misrepresent the search.
    parts.push(query.matchAny && conditions.length > 1
      ? `(${conditions.join(t(' or '))})`
      : conditions.join(' · '))
  }

  return parts.join(' · ')
}

function describeCondition(t: Translate, condition: Condition): string {
  const field = fieldLabel(t, condition.field)
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
