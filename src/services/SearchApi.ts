/**
 * Thin API client for the search endpoints. `@nextcloud/axios` handles the CSRF
 * token + session cookie automatically.
 *
 * Search is a POST because a query is a structured document (a condition list),
 * not a handful of scalars — the same reason WebDAV uses a SEARCH body.
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { API_BASE } from '../constants'
import { RETIRED_FIELDS } from '../filters/fields'
import { useI18n } from '../composables/useI18n'
import type {
  FieldsResponse,
  Principal,
  ScopeEntity,
  ScopeFileEntity,
  ScopeVocabulary,
  SearchHistory,
  SearchRequest,
  SearchResponse,
  SearchState,
  StoredSearch,
  Preferences,
} from '../types/Search'

const url = (path: string) => generateUrl(`${API_BASE}${path}`)

/**
 * A stored query as this version of the interface reads it.
 *
 * Searches kept from before the term and the phrase to look inside files became
 * one question hold the two in separate fields — `{ term: '', content: 'lease' }`
 * for "look inside the files", sorted by the index's own relevance. Read back
 * unchanged they would open with an empty search box, so the phrase becomes the
 * term with the toggle on: the same search, expressed in the controls that now
 * run it. Relevance goes with it — nothing offers that ordering any more.
 *
 * Conditions on a field this app no longer offers are dropped the same way: the
 * server refuses them, and the filter rows should show what really runs. See
 * RETIRED_FIELDS.
 *
 * Applied at the door every stored search comes through, so nothing downstream
 * has to know the old shape existed.
 * @param query whatever the server had stored for this search
 */
function restored(query: SearchState & { content?: string }): SearchState {
  const phrase = (query.content ?? '').trim()
  const rest: SearchState & { content?: string } = {
    ...query,
    conditions: (query.conditions ?? []).filter((c) => !RETIRED_FIELDS.includes(c.field)),
  }
  delete rest.content
  if (phrase === '') {
    return rest
  }

  return {
    ...rest,
    term: rest.term.trim() === '' ? phrase : rest.term,
    searchContent: true,
    sort: rest.sort === 'relevance' ? 'mtime' : rest.sort,
  }
}

/**
 * @param entry one stored search, straight off the wire
 */
function restoredSearch(entry: StoredSearch): StoredSearch {
  return { ...entry, query: restored(entry.query) }
}

/**
 * Turns an axios failure into the server's own message where it sent one.
 * @param error
 */
function describe(error: unknown): Error {
  const message = (error as { response?: { data?: { error?: string } } })
    ?.response?.data?.error
  return new Error(message || useI18n().t('Search failed'))
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

  /**
   * The workspaces and entity types this user may scope by. Empty lists are a
   * normal answer, not a failure: it means DoCoNEXT Core is not installed.
   */
  async scope(): Promise<ScopeVocabulary> {
    try {
      const { data } = await axios.get<ScopeVocabulary>(url('/scope'))
      return data
    } catch (error) {
      throw describe(error)
    }
  },

  /**
   * Typeahead over entities, optionally narrowed to one type. An empty term
   * returns suggestions instead: starred entities, then recently changed ones.
   * @param term what the user has typed so far, or '' for suggestions
   * @param entityTypeId restrict to this type, when one is chosen
   */
  async scopeEntities(term: string, entityTypeId?: number): Promise<ScopeEntity[]> {
    try {
      const { data } = await axios.get<{ entities: ScopeEntity[] }>(url('/scope/entities'), {
        params: { q: term, entityTypeId },
      })
      return data.entities ?? []
    } catch (error) {
      throw describe(error)
    }
  },

  /**
   * The DoCoNEXT Core entity a file belongs to, or null when it belongs to none
   * — which is a normal answer, not a failure.
   * @param fileId the file to ask about
   */
  async entityForFile(fileId: number): Promise<ScopeFileEntity | null> {
    try {
      const { data } = await axios.get<{ entity: ScopeFileEntity | null }>(
        url('/scope/entity-for-file'),
        { params: { fileId } },
      )
      return data.entity ?? null
    } catch (error) {
      throw describe(error)
    }
  },

  /**
   * People a principal metadata field can be filtered on, matched on name.
   *
   * Finder asks its own server, which asks DoCoNEXT Core: the field's own rules
   * about who may be picked live there. An empty list is a normal answer — no
   * Core, or a field that names nobody — and the row falls back to a plain box.
   * @param field the field being filtered, e.g. meta:dcn_core_behandelaar
   * @param term what has been typed
   */
  async principals(field: string, term: string): Promise<Principal[]> {
    try {
      const { data } = await axios.get<{ principals: Principal[] }>(
        url('/principals'),
        { params: { field, q: term } },
      )
      return data.principals ?? []
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
      return {
        saved: (data.saved ?? []).map(restoredSearch),
        recents: (data.recents ?? []).map(restoredSearch),
      }
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
