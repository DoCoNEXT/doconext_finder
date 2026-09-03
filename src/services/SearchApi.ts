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
import type {
  FieldsResponse,
  SearchHistory,
  SearchRequest,
  SearchResponse,
  SearchState,
  StoredSearch,
  Preferences,
} from '../types/Search'

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

  async history(): Promise<SearchHistory> {
    try {
      const { data } = await axios.get<SearchHistory>(url('/searches'))
      return data
    } catch (error) {
      throw describe(error)
    }
  },

  /**
   * Records that a search ran. Best-effort by design: history is a convenience,
   * and a failure here must never make a successful search look broken.
   * @param query the interface state that reproduces the search
   */
  async recordRecent(query: SearchState): Promise<void> {
    try {
      await axios.post(url('/searches/recent'), { query })
    } catch {
      // ignored on purpose — see above
    }
  },

  /**
   * @param name
   * @param description
   * @param query
   */
  async save(name: string, description: string, query: SearchState): Promise<StoredSearch> {
    try {
      const { data } = await axios.post<StoredSearch>(url('/searches'), { name, description, query })
      return data
    } catch (error) {
      throw describe(error)
    }
  },

  /**
   * @param id
   * @param name
   * @param description
   */
  async rename(id: number, name: string, description: string): Promise<StoredSearch> {
    try {
      const { data } = await axios.put<StoredSearch>(url(`/searches/${id}`), { name, description })
      return data
    } catch (error) {
      throw describe(error)
    }
  },

  /**
   * Replaces what a saved search searches for, keeping its name and its id.
   * @param id
   * @param query
   */
  async replaceQuery(id: number, query: SearchState): Promise<StoredSearch> {
    try {
      const { data } = await axios.put<StoredSearch>(url(`/searches/${id}/query`), { query })
      return data
    } catch (error) {
      throw describe(error)
    }
  },

  /**
   * @param id
   */
  async remove(id: number): Promise<void> {
    try {
      await axios.delete(url(`/searches/${id}`))
    } catch (error) {
      throw describe(error)
    }
  },

  async clearRecents(): Promise<void> {
    try {
      await axios.delete(url('/searches/recent'))
    } catch (error) {
      throw describe(error)
    }
  },

  /**
   * Moves a saved search to the top of the list. Best-effort: ordering is not
   * worth failing a run over.
   * @param id
   */
  async markRun(id: number): Promise<void> {
    try {
      await axios.post(url(`/searches/${id}/run`))
    } catch {
      // ignored on purpose — see above
    }
  },

  async preferences(): Promise<Preferences> {
    try {
      const { data } = await axios.get<Preferences>(url('/preferences'))
      return data
    } catch (error) {
      throw describe(error)
    }
  },

  /**
   * Returns what the server actually stored, not what was sent: it drops unknown
   * columns and appends missing ones, so the client adopts that rather than
   * holding a copy that quietly disagrees.
   * @param preferences
   */
  async savePreferences(preferences: Preferences): Promise<Preferences> {
    try {
      const { data } = await axios.put<Preferences>(url('/preferences'), preferences)
      return data
    } catch (error) {
      throw describe(error)
    }
  },
}
