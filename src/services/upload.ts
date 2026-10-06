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

/** Thrown by `uploadTo` when the path is taken and overwriting was not asked for. */
export class FileExistsError extends Error {
  readonly path: string

  constructor(path: string) {
    super(`${path} already exists`)
    this.path = path
  }
}

/**
 * Writes a local file to a path relative to the user's files root.
 *
 * Only replaces an existing file when told to: a new version of a known file
 * is meant to overwrite, an upload into a folder is not, and WebDAV would
 * quietly replace whatever carries the same name. `If-None-Match: *` makes the
 * server refuse instead (412), which becomes `FileExistsError`.
 * @param path target path, e.g. Legal/Dossiers/case.pdf
 * @param content the picked file
 * @param overwrite whether an existing file at that path may be replaced
 */
export async function uploadTo(path: string, content: File, overwrite: boolean): Promise<void> {
  try {
    await axios.put(davUrl(path), content, {
      headers: {
        'Content-Type': content.type || 'application/octet-stream',
        ...(overwrite ? {} : { 'If-None-Match': '*' }),
      },
    })
  } catch (error) {
    if (!overwrite && (error as { response?: { status?: number } } | null)?.response?.status === 412) {
      throw new FileExistsError(path)
    }
    throw error
  }
}
