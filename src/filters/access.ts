/**
 * What the current user may do with a file's content.
 *
 * A search lists what the user may see, which is not always what they may read:
 * a team folder ACL or a share can grant the folder while denying "Read" on what
 * is in it. The server then answers 404 to a download and to the preview, so
 * offering either only leads to an error that names the wrong cause.
 *
 * The rules mirror the Files app's own (apps/files/src/utils/permissions.ts),
 * so Finder offers a download exactly when the file list does.
 */
import type { FileResult } from '../types/Search'

/** Permission.READ in @nextcloud/files; written out to avoid the dependency. */
const READ = 1

export function canRead(file: Pick<FileResult, 'permissions'>): boolean {
  return (file.permissions & READ) !== 0
}

export function canDownload(file: Pick<FileResult, 'permissions' | 'shareAttributes'>): boolean {
  if (!canRead(file)) {
    return false
  }

  // A received share with "Allow download" off says so here, as a JSON list.
  if (file.shareAttributes) {
    try {
      const list = JSON.parse(file.shareAttributes) as Array<{ scope: string, key: string, value: unknown }>
      const download = list.find(({ scope, key }) => scope === 'permissions' && key === 'download')
      if (download !== undefined) {
        return download.value === true
      }
    } catch {
      // Unreadable attributes say nothing; the server decides.
    }
  }

  return true
}
