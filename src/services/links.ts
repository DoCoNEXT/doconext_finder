/**
 * Links to one stored search: `/apps/<id>/?search=<id>`.
 *
 * What replaces mailing a search around as a file. The link carries only the
 * id; whoever opens it gets the search only if it is theirs or shared with
 * them, because the app looks it up in their own list — the link is an
 * address, not a key.
 */
import { generateUrl } from '@nextcloud/router'
import { APP_ID } from '../constants'

const PARAM = 'search'

/**
 * The absolute link to a saved search, for pasting into a chat or a mail.
 * @param id the saved search
 */
export function searchLink(id: number): string {
  return new URL(`${generateUrl(`/apps/${APP_ID}/`)}?${PARAM}=${id}`, window.location.origin).href
}

/**
 * The search the page was opened for, if any — and takes it out of the address
 * bar, so a reload does not run it a second time and a bookmark of the page
 * afterwards is a bookmark of the app, not of that one search.
 * @return the id from the link, or null when there is none worth trying
 */
export function takeLinkedSearch(): number | null {
  const url = new URL(window.location.href)
  if (!url.searchParams.has(PARAM)) {
    return null
  }
  const id = Number(url.searchParams.get(PARAM))
  url.searchParams.delete(PARAM)
  window.history.replaceState(window.history.state, '', url.pathname + url.search + url.hash)

  return Number.isInteger(id) && id > 0 ? id : null
}
