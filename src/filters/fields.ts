/**
 * Presentation for the filterable fields the backend advertises.
 *
 * GET /api/search/fields returns filecache column names (`mtime`,
 * `creation_time`, …). Those are the wire contract and must not leak into the
 * UI, so this maps each to a label and an input kind. A field the server offers
 * but this map does not know still shows — under its raw name — rather than
 * disappearing, so a server-side addition degrades to ugly instead of invisible.
 */
import type { Component } from 'vue'
import {
  File,
  FileArchive,
  FileChartColumn,
  FileImage,
  FileMusic,
  FilePenLine,
  FileSpreadsheet,
  FileText,
  FileVideoCamera,
  Folder,
} from '@lucide/vue'

/** How the value box behaves for a field. */
export type InputKind = 'text' | 'date' | 'size' | 'none'

export type Translate = (_text: string, _vars?: Record<string, unknown>) => string

/**
 * Fields this app once offered and no longer does. A search stored back then
 * can still carry them, and the server refuses a field it does not know — so
 * they are dropped as the search is loaded, where the filter rows then show
 * what is really being asked.
 *
 * - `owner` ("Created by"): Nextcloud cannot search on who made a file. Its own
 *   `owner` field matches through the share table, and a team folder records
 *   no creator at all.
 * - `path` ("Folder path"): the folder scope asks the same question.
 */
export const RETIRED_FIELDS: readonly string[] = ['owner', 'path']

/** Which input a field needs. Free of wording, so it needs no translation. */
const FIELD_INPUTS: Record<string, InputKind> = {
  name: 'text',
  mimetype: 'text',
  size: 'size',
  mtime: 'date',
  creation_time: 'date',
  favorite: 'none',
  tagname: 'text',
}

export function fieldInput(field: string): InputKind {
  return FIELD_INPUTS[field] ?? 'text'
}

/**
 * Wording is resolved at call time with the caller's `t`, never cached at module
 * load: the l10n bundle is registered after this module is evaluated, so a
 * constant would freeze the English text in place and never follow a language
 * change.
 *
 * An unknown field falls back to its raw column name rather than vanishing, so a
 * field added server-side degrades to ugly instead of invisible.
 * @param t
 * @param field
 */
export function fieldLabel(t: Translate, field: string): string {
  switch (field) {
    case 'name': return t('Name')
    case 'mimetype': return t('Media type')
    case 'size': return t('Size')
    case 'mtime': return t('Modified')
    case 'creation_time': return t('Created')
    case 'favorite': return t('Favorite')
    case 'tagname': return t('Tag')
    default: return field
  }
}

export function fieldHint(t: Translate, field: string): string {
  switch (field) {
    case 'mimetype': return t('e.g. application/pdf')
    default: return ''
  }
}

/**
 * Operator wording depends on the input kind: "before/after" reads right for a
 * date but not for a size, where the same comparison means "smaller/larger than".
 * @param t
 * @param field
 * @param operator
 */
export function operatorLabel(t: Translate, field: string, operator: string): string {
  const kind = fieldInput(field)

  if (kind === 'none') {
    return t('is set')
  }

  if (kind === 'date') {
    switch (operator) {
      case 'lt': return t('before')
      case 'lte': return t('on or before')
      case 'gt': return t('after')
      case 'gte': return t('on or after')
    }
  }

  if (kind === 'size') {
    switch (operator) {
      case 'lt': return t('smaller than')
      case 'lte': return t('at most')
      case 'gt': return t('larger than')
      case 'gte': return t('at least')
    }
  }

  switch (operator) {
    case 'eq': return t('is')
    case 'contains': return t('contains')
    default: return operator
  }
}

/** Bytes ⇄ MB, so the size box takes a figure a person can type. */
export const MB = 1024 * 1024

export function toBytes(megabytes: string): number {
  const value = Number.parseFloat(megabytes)
  return Number.isFinite(value) && value >= 0 ? Math.round(value * MB) : 0
}

export function toMegabytes(bytes: number | string): string {
  const value = Number(bytes)
  return Number.isFinite(value) ? String(Math.round((value / MB) * 1000) / 1000) : ''
}

/**
 * A yyyy-mm-dd box ⇄ the Unix seconds the backend compares against.
 * @param date
 */
export function toUnix(date: string): number {
  const parsed = Date.parse(`${date}T00:00:00`)
  return Number.isNaN(parsed) ? 0 : Math.floor(parsed / 1000)
}

export function toDateInput(unix: number | string): string {
  const value = Number(unix)
  if (!Number.isFinite(value) || value <= 0) {
    return ''
  }
  const d = new Date(value * 1000)
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

/**
 * Mimetype → the icon that stands for it, mirroring how the Files app groups
 * types. Lucide draws no PDF of its own: the densely lined file is the closest
 * to a page you read, which leaves the written-on file for word processors.
 * @param mimetype
 * @param isFolder
 */
export function iconFor(mimetype: string, isFolder: boolean): Component {
  if (isFolder) {
    return Folder
  }
  if (mimetype.startsWith('image/')) {
    return FileImage
  }
  if (mimetype.startsWith('video/')) {
    return FileVideoCamera
  }
  if (mimetype.startsWith('audio/')) {
    return FileMusic
  }
  if (mimetype === 'application/pdf') {
    return FileText
  }
  if (/word|opendocument\.text/.test(mimetype)) {
    return FilePenLine
  }
  if (/excel|spreadsheet|csv/.test(mimetype)) {
    return FileSpreadsheet
  }
  if (/powerpoint|presentation/.test(mimetype)) {
    return FileChartColumn
  }
  if (/zip|tar|gzip|compressed|rar/.test(mimetype)) {
    return FileArchive
  }
  return File
}
