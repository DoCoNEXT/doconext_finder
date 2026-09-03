/**
 * WebDAV addressing for the current user's files.
 *
 * One place, because three things need the same URL: downloading a file,
 * replacing it with a new version, and adding files to a folder.
 */
import { generateRemoteUrl } from '@nextcloud/router'
import { getCurrentUser } from '@nextcloud/auth'
import type { FileResult } from '../types/Search'

/**
 * Percent-encodes each segment; a path is not a single component.
 * @param path a slash-separated path
 */
export function encodePath(path: string): string {
  return path.split('/').map(encodeURIComponent).join('/')
}

/**
 * The DAV URL of a path relative to the user's files root.
 * @param path e.g. Legal/Dossiers/case.pdf
 */
export function davUrl(path: string): string {
  const user = getCurrentUser()?.uid ?? ''

  return `${generateRemoteUrl('dav')}/files/${encodeURIComponent(user)}/${encodePath(path.replace(/^\/+/, ''))}`
}

export function davUrlFor(file: FileResult): string {
  return davUrl(file.path)
}
