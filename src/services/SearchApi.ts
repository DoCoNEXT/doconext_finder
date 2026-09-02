/**
 * Thin API client for the search endpoints. @nextcloud/axios handles the CSRF
 * token + session cookie automatically.
 *
 * Search is a POST because a query is a structured document (a condition list),
 * not a handful of scalars — the same reason WebDAV uses a SEARCH body.
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { API_BASE } from '../constants'
import type { FieldsResponse, SearchRequest, SearchResponse } from '../types/Search'

const url = (path: string) => generateUrl(`${API_BASE}${path}`)

/**
 * Turns an axios failure into the server's own message where it sent one.
 * @param error
 */
function describe(error: unknown): Error {
  const message = (error as { response?: { data?: { error?: string } } })
    ?.response?.data?.error
  return new Error(message || (error as Error)?.message || 'Search failed')
}

export const SearchApi = {
  async fields(): Promise<FieldsResponse> {
    try {
      const { data } = await axios.get<FieldsResponse>(url('/search/fields'))
      return data
    } catch (error) {
      throw describe(error)
    }
  },

  async search(request: SearchRequest): Promise<SearchResponse> {
    try {
      const { data } = await axios.post<SearchResponse>(url('/search'), request)
      return data
    } catch (error) {
      throw describe(error)
    }
  },

  /**
   * Nextcloud has no OCS favorites route; this is the app's own endpoint.
   * @param fileId
   * @param favorite
   */
  async setFavorite(fileId: number, favorite: boolean): Promise<void> {
    try {
      const path = `/files/${fileId}/favorite`
      await (favorite ? axios.post(url(path)) : axios.delete(url(path)))
    } catch (error) {
      throw describe(error)
    }
  },
}
