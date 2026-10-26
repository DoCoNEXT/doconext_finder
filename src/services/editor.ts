/**
 * Editing a file where Files edits it: in its editor, through Nextcloud's
 * Viewer, which the page loads when that app is installed (PageController).
 * The same rule as DoCoNEXT Core's Folder view, so Edit is offered alike in
 * both apps: an editor is installed for the file's type, and the user may
 * change it.
 */
import type { FileResult } from '../types/Search'

/** Permission.UPDATE in `@nextcloud/files`; written out to avoid the dependency. */
const UPDATE = 2

/** Viewer handlers that edit, rather than show, a file. */
const EDITORS = ['text', 'richdocuments', 'onlyoffice']

interface ViewerHandler {
  id: string
  mimes?: string[]
}

interface ViewerApi {
  openWith(handlerId: string, options: { path: string }): void
  availableHandlers?: ViewerHandler[]
}

function viewer(): ViewerApi | null {
  const oca = (window as unknown as { OCA?: { Viewer?: ViewerApi } }).OCA
  return oca?.Viewer ?? null
}

/**
 * The editor this file opens in, or null: none installed for its type, or the
 * user may not change it.
 * @param file the row
 */
export function editorFor(file: Pick<FileResult, 'isFolder' | 'mimetype' | 'permissions'>): string | null {
  if (file.isFolder || (file.permissions & UPDATE) === 0) {
    return null
  }
  const handler = viewer()?.availableHandlers?.find((h) => EDITORS.includes(h.id) && (h.mimes ?? []).includes(file.mimetype))

  return handler?.id ?? null
}

/**
 * @param file the row
 * @param editor the handler editorFor() named
 */
export function openInEditor(file: Pick<FileResult, 'path'>, editor: string): void {
  viewer()?.openWith(editor, { path: `/${file.path.replace(/^\/+/, '')}` })
}
