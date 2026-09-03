/**
 * What a grid column is called, what it shows, and whether it can be sorted.
 *
 * One place rather than three: the table, the columns dialog and the details
 * panel all name the same fields, and they drifted apart the moment a column was
 * added. Metadata labels come from the server's registry, so a key reads the
 * same wherever it appears.
 */
import { isMetadataField, metadataKeyOf } from './metadata'
import { folderOf, typeName } from './grouping'
import type { Translate } from './presets'
import type { FileResult, MetadataField } from '../types/Search'

/**
 * The built-in name of a column, ignoring any user rename.
 * @param t translation function
 * @param id column id, or a `meta:` field
 * @param metadata the server's metadata registry, for labelling `meta:` ids
 */
export function columnLabel(t: Translate, id: string, metadata: MetadataField[] = []): string {
  if (isMetadataField(id)) {
    return metadata.find((f) => f.field === id)?.label ?? metadataKeyOf(id)
  }
  switch (id) {
    case 'name': return t('Name')
    case 'folder': return t('Folder')
    case 'size': return t('Size')
    case 'modified': return t('Modified')
    case 'created': return t('Created')
    // "Type" is the readable category you filter by; "Media type" the exact
    // mimetype you paste into a condition. They are different questions.
    case 'type': return t('Type')
    case 'mimetype': return t('Media type')
    case 'createdBy': return t('Created by')
    case 'modifiedBy': return t('Modified by')
    default: return id
  }
}

/**
 * The text a cell shows. Empty rather than absent, so the grid keeps its shape.
 * @param t translation function
 * @param file the row
 * @param id column id, or a `meta:` field
 */
export function columnValue(t: Translate, file: FileResult, id: string): string {
  if (isMetadataField(id)) {
    return file.metadata?.[metadataKeyOf(id)] ?? ''
  }
  switch (id) {
    case 'folder': return folderOf(file)
    case 'size': return file.isFolder ? '—' : formatSize(file.size)
    case 'modified': return formatDate(file.mtime)
    case 'created': return file.creationTime > 0 ? formatDate(file.creationTime) : '—'
    case 'type': return typeName(t, file)
    case 'mimetype': return file.isFolder ? '—' : file.mimetype
    case 'createdBy': return file.createdBy ?? ''
    case 'modifiedBy': return file.modifiedBy ?? ''
    default: return ''
  }
}

/**
 * Which backend sort field a column maps to, or null when it cannot be sorted.
 *
 * Media type, Created by and Modified by are absent on purpose: the backend
 * sorts on a fixed set of filecache columns, and these either are not one
 * (Modified by lives in the Versions app's table) or sort by an internal id
 * rather than by the text on screen (Media type).
 * @param id column id
 * @param metadata the server's metadata registry — only indexed keys are sortable
 */
export function columnSortField(id: string, metadata: MetadataField[] = []): string | null {
  if (isMetadataField(id)) {
    return metadata.find((f) => f.field === id)?.filterable ? id : null
  }
  switch (id) {
    case 'name': return 'name'
    case 'size': return 'size'
    case 'modified': return 'mtime'
    case 'created': return 'creation_time'
    default: return null
  }
}

/**
 * Right-aligned columns: only figures, where the digits should line up.
 * @param id column id
 */
export function isNumericColumn(id: string): boolean {
  return id === 'size'
}

export function formatSize(bytes: number): string {
  if (bytes < 1024) {
    return `${bytes} B`
  }
  const units = ['KB', 'MB', 'GB', 'TB']
  let value = bytes / 1024
  let unit = 0
  while (value >= 1024 && unit < units.length - 1) {
    value /= 1024
    unit++
  }
  return `${value.toFixed(value < 10 ? 1 : 0)} ${units[unit]}`
}

export function formatDate(unixSeconds: number): string {
  return new Date(unixSeconds * 1000).toLocaleString()
}
