/**
 * Downloading a file.
 *
 * The WebDAV URL is the file itself, but Nextcloud serves it without a
 * `Content-Disposition: attachment` header — verified against the running
 * server — so a plain link makes the browser *render* a PDF or an image instead
 * of saving it, which reads as navigating away from the results.
 *
 * The `download` attribute forces saving, and is honoured because the URL is
 * same-origin. It also streams: fetching the bytes into a blob first would work
 * too, but would hold whole files in memory for no gain.
 */
import { davUrlFor } from './dav'
import type { FileResult } from '../types/Search'

export function downloadUrl(file: FileResult): string {
  return davUrlFor(file)
}

export function downloadFile(file: FileResult): void {
  const link = document.createElement('a')
  link.href = downloadUrl(file)
  link.download = file.name
  link.rel = 'noreferrer'
  document.body.appendChild(link)
  link.click()
  link.remove()
}
