/**
 * Opening a file on the person's own machine. Two routes, because the two cases
 * genuinely differ, and which file takes which route is an administrator's
 * choice rather than a constant in this file.
 *
 * **Ordinary files** go to the Nextcloud desktop client, with the same handshake
 * the Files app uses: ask the server for a one-shot token, then navigate to
 * `nc://open/…`, which the client registers as a protocol handler. It opens the
 * file in the synced folder, so edits sync back — which is the whole reason to
 * prefer it over downloading a copy.
 *
 * **Email files** go to DoCoNEXT Bridge instead. A `.msg` cannot be opened by any
 * mail client outside Windows without being converted to `.eml` first, and no
 * amount of syncing helps with that. A copy is also the right semantics there:
 * you reply to a message, you do not edit it in place.
 *
 * Neither route reports back. A browser cannot see whether a protocol handler
 * exists, so the caller only ever learns that the request was made.
 */
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { getCurrentUser } from '@nextcloud/auth'
import { encodePath } from './dav'
import { BRIDGE_EXTENSIONS } from '../constants'
import type { FileResult } from '../types/Search'

interface TokenResponse { ocs: { data: { token: string } } }

/**
 * Whether this file is one the desktop client cannot usefully open.
 *
 * The list comes from the server, not from here: DoCoNEXT Core offers the same
 * action, and an administrator may add formats as they turn up.
 * @param file the row being opened
 */
function goesToBridge(file: FileResult): boolean {
  const name = file.name.toLowerCase()

  return BRIDGE_EXTENSIONS.some((extension) => name.endsWith(`.${extension}`))
}

export async function openLocally(file: FileResult): Promise<void> {
  if (goesToBridge(file)) {
    // Only a file id travels — never a path. Any page can invoke a custom scheme,
    // so the bridge resolves and fetches the file itself with its own credentials
    // rather than trusting anything in the URL.
    window.open(`doconext://open?fileId=${encodeURIComponent(String(file.fileid))}`, '_self')

    return
  }

  const path = `/${file.path.replace(/^\/+/, '')}`

  const { data } = await axios.post<TokenResponse>(
    `${generateOcsUrl('apps/files/api/v1')}/openlocaleditor?format=json`,
    { path },
  )

  const uid = getCurrentUser()?.uid ?? ''
  const url = `nc://open/${uid}@${window.location.host}${encodePath(path)}?token=${data.ocs.data.token}`

  // _self, not a new tab: a protocol handler leaves an empty tab behind.
  window.open(url, '_self')
}
