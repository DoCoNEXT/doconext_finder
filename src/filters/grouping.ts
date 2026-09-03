/**
 * Client-side grouping of the loaded result rows, over any number of levels.
 *
 * Deliberately client-side: grouping is a way of reading a result set, not of
 * selecting one, and the search backend has no grouping of its own. The cost is
 * that it only covers what is loaded — which is why "Load all" exists, and why
 * the view options say so while more pages are outstanding.
 *
 * The output is a flat row list rather than a tree. A table cannot nest, so a
 * tree would have to be flattened at render time anyway; doing it here keeps the
 * component to a single v-for and makes depth free.
 */
import { isMetadataField, metadataKeyOf } from './metadata'
import type { Translate } from './presets'
import type { FileResult } from '../types/Search'

export type GridRow =
  | { kind: 'group', id: string, label: string, level: number, count: number }
  | { kind: 'file', id: string, file: FileResult }

export function groupLabel(t: Translate, grouping: string): string {
  switch (grouping) {
    case 'folder': return t('Folder')
    case 'type': return t('Type')
    case 'modified': return t('Modified date')
    default: return grouping
  }
}

/**
 * A readable type name, coarser than the mimetype and matching the Type presets.
 * @param t
 * @param file
 */
export function typeName(t: Translate, file: FileResult): string {
  if (file.isFolder) {
    return t('Folders')
  }
  const mime = file.mimetype
  if (mime.startsWith('image/')) {
    return t('Images')
  }
  if (mime.startsWith('video/')) {
    return t('Video')
  }
  if (mime.startsWith('audio/')) {
    return t('Audio')
  }
  if (mime === 'application/pdf') {
    return t('PDFs')
  }
  if (/word|opendocument\.text|text\/plain|markdown/.test(mime)) {
    return t('Documents')
  }
  if (/excel|spreadsheet|csv/.test(mime)) {
    return t('Spreadsheets')
  }
  if (/powerpoint|presentation/.test(mime)) {
    return t('Presentations')
  }
  if (/rfc822|ms-outlook/.test(mime)) {
    return t('Emails')
  }
  if (/zip|tar|gzip|compressed|rar/.test(mime)) {
    return t('Archives')
  }
  return t('Other')
}

export function folderOf(file: FileResult): string {
  const at = file.path.lastIndexOf('/')
  return at === -1 ? '/' : file.path.slice(0, at)
}

/**
 * Flattens the rows into group headers and files, nesting one level per entry in
 * `groupings`.
 *
 * Groups appear in the order their first row arrives, so grouping never silently
 * reorders a server-sorted result: the groups follow the sort, and rows keep
 * their order within each group.
 * @param t translation function
 * @param files the loaded rows
 * @param groupings ordered grouping levels, outermost first
 */
export function buildRows(t: Translate, files: FileResult[], groupings: string[]): GridRow[] {
  if (groupings.length === 0) {
    return files.map((file) => ({ kind: 'file', id: String(file.fileid), file }))
  }

  return walk(t, files, groupings, 0, '')
}

function walk(
  t: Translate,
  files: FileResult[],
  groupings: string[],
  level: number,
  parentKey: string,
): GridRow[] {
  const grouping = groupings[level]
  if (grouping === undefined) {
    return files.map((file) => ({
      kind: 'file',
      // Prefixed with the group path: the same file can appear under different
      // branches, and Vue needs the keys to stay distinct.
      id: `${parentKey}/${file.fileid}`,
      file,
    }))
  }

  const buckets = new Map<string, FileResult[]>()
  for (const file of files) {
    const label = keyFor(t, file, grouping)
    const bucket = buckets.get(label)
    if (bucket) {
      bucket.push(file)
    } else {
      buckets.set(label, [file])
    }
  }

  const rows: GridRow[] = []
  for (const [label, bucket] of buckets) {
    const key = `${parentKey}/${grouping}=${label}`
    rows.push({ kind: 'group', id: key, label, level, count: bucket.length })
    rows.push(...walk(t, bucket, groupings, level + 1, key))
  }

  return rows
}

function keyFor(t: Translate, file: FileResult, grouping: string): string {
  // A file without the field still needs a group, or it would vanish from a
  // grouped view while counting toward the result total.
  if (isMetadataField(grouping)) {
    return file.metadata?.[metadataKeyOf(grouping)] || t('(not set)')
  }
  switch (grouping) {
    case 'folder': return folderOf(file) || '/'
    case 'type': return typeName(t, file)
    case 'modified': return new Date(file.mtime * 1000).toLocaleDateString()
    default: return ''
  }
}
