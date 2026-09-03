/**
 * Putting files back: a new version of one file, or new files into a folder.
 *
 * Both are a WebDAV PUT — Nextcloud versions a file automatically when its
 * content is replaced, so "upload a new version" is simply writing to the same
 * path. Uploads go through a hidden <input type="file"> rather than a drop zone:
 * the commands live in a menu, and a menu item has to open a picker.
 *
 * Deliberately simple: no chunked upload, no progress. A result grid is where
 * you *find* things; the moment an upload is big enough to need a progress bar,
 * the Files app is the better place for it.
 */
import axios from '@nextcloud/axios'
import { davUrl } from './dav'

/**
 * Opens the browser's file picker and resolves with what was chosen.
 * @param multiple whether more than one file may be picked
 */
export function pickLocalFiles(multiple: boolean): Promise<File[]> {
  return new Promise((resolve) => {
    const input = document.createElement('input')
    input.type = 'file'
    input.multiple = multiple
    input.style.display = 'none'

    // A cancelled picker fires no 'change' event in most browsers, so the
    // element is cleaned up on either outcome rather than only on success.
    const finish = (files: File[]) => {
      input.remove()
      resolve(files)
    }

    input.addEventListener('change', () => finish(Array.from(input.files ?? [])))
    input.addEventListener('cancel', () => finish([]))
    document.body.appendChild(input)
    input.click()
  })
}

/**
 * Writes a local file to a path relative to the user's files root.
 * @param path target path, e.g. Legal/Dossiers/case.pdf
 * @param content the picked file
 */
export async function uploadTo(path: string, content: File): Promise<void> {
  await axios.put(davUrl(path), content, {
    headers: { 'Content-Type': content.type || 'application/octet-stream' },
  })
}
