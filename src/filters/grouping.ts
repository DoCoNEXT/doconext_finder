/**
 * Client-side grouping of the loaded result rows.
 *
 * Deliberately client-side: grouping is a way of reading a result set, not of
 * selecting one, and the search backend has no grouping of its own. The cost is
 * that it only covers what is loaded — which is why "Load all" exists.
 */
import type { Translate } from './presets'
import type { FileResult } from '../types/Search'

export interface FileGroup {
  key: string
  label: string
  files: FileResult[]
}

export function groupLabel(t: Translate, grouping: string): string {
  switch (grouping) {
    case 'folder': return t('Folder')
    case 'type': return t('Type')
    case 'modified': return t('Modified')
    default: return t('None')
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
 * Groups in the order the rows arrive, so grouping never silently reorders a
 * sorted result set — the groups follow the sort, and rows keep their order
 * inside each group.
 * @param t translation function
 * @param files the loaded rows
 * @param grouping '' for none, else folder | type | modified
 */
export function groupFiles(t: Translate, files: FileResult[], grouping: string): FileGroup[] {
  if (!grouping) {
    return [{ key: '', label: '', files }]
  }

  const groups = new Map<string, FileGroup>()
  for (const file of files) {
    const label = keyFor(t, file, grouping)
    let group = groups.get(label)
    if (!group) {
      group = { key: label, label, files: [] }
      groups.set(label, group)
    }
    group.files.push(file)
  }

  return [...groups.values()]
}

function keyFor(t: Translate, file: FileResult, grouping: string): string {
  switch (grouping) {
    case 'folder': return folderOf(file) || '/'
    case 'type': return typeName(t, file)
    case 'modified': return new Date(file.mtime * 1000).toLocaleDateString()
    default: return ''
  }
}
