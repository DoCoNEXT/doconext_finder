/**
 * Handing a file to the Nextcloud desktop client.
 *
 * The same handshake the Files app uses: ask the server for a one-shot token,
 * then navigate to `nc://open/…`, which the desktop client registers as a
 * protocol handler. Nothing reports back whether it worked — the browser cannot
 * see whether a protocol handler exists — so the caller only learns that the
 * request was made.
 */
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { getCurrentUser } from '@nextcloud/auth'
import { encodePath } from './dav'
import type { FileResult } from '../types/Search'

interface TokenResponse { ocs: { data: { token: string } } }

export async function openLocally(file: FileResult): Promise<void> {
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
