/**
 * The live search: its interface state, its results, and how to run it.
 *
 * Held in a store rather than in the search view because other pages hand
 * searches to it — running a saved search switches to Search with that query
 * already loaded — and because Favorites reuses the same result plumbing.
 */
import { defineStore } from 'pinia'
import { SearchApi } from '../services/SearchApi'
import { anyTime, anyType, fileTypePresets, modifiedAfter, modifiedPresets } from '../filters/presets'
import type { Translate } from '../filters/presets'
import type { Condition, FieldsResponse, FileResult, SearchState } from '../types/Search'

const PAGE_SIZE = 50

/** How many pages "Load all" will fetch before stopping. */
const MAX_PAGES = 40

export function emptyState(): SearchState {
  return {
    term: '',
    typePreset: 'any',
    modifiedPreset: 'any',
    conditions: [],
    matchAny: false,
    sort: 'mtime',
    descending: true,
  }
}

interface State {
  query: SearchState
  schema: FieldsResponse | null
  results: FileResult[]
  hasMore: boolean
  offset: number
  loading: boolean
  searched: boolean
  loadedAll: boolean
  error: string
}

export const useSearchStore = defineStore('search', {
  state: (): State => ({
    query: emptyState(),
    schema: null,
    results: [],
    hasMore: false,
    offset: 0,
    loading: false,
    searched: false,
    loadedAll: false,
    error: '',
  }),

  getters: {
    pageSize: () => PAGE_SIZE,

    /**
     * True when there is anything to search by — a blank search is refused.
     * @param state
     */
    hasCriteria(state): boolean {
      return state.query.term.trim() !== ''
        || state.query.conditions.length > 0
        || state.query.typePreset !== 'any'
        || state.query.modifiedPreset !== 'any'
    },
  },

  actions: {
    async loadSchema() {
      if (this.schema) {
        return
      }
      try {
        this.schema = await SearchApi.fields()
      } catch (e) {
        this.error = (e as Error).message
      }
    },

    reset() {
      this.query = emptyState()
      this.results = []
      this.searched = false
      this.loadedAll = false
      this.error = ''
      this.offset = 0
    },

    /**
     * Loads a stored search's interface state, ready to run.
     * @param query
     */
    apply(query: SearchState) {
      this.query = { ...emptyState(), ...query, conditions: [...(query.conditions ?? [])] }
    },

    /**
     * Translates the interface state into a request. Presets are resolved here,
     * at run time, which is why history stores their ids: "Last 7 days" must mean
     * seven days before *this* run, not before the run that was saved.
     * @param t translation function, for resolving preset ids to their definitions
     * @param offset row to start at
     * @param limit how many rows to ask for
     */
    request(t: Translate, offset: number, limit: number) {
      const type = fileTypePresets(t).find((p) => p.id === this.query.typePreset) ?? anyType(t)
      const time = modifiedPresets(t).find((p) => p.id === this.query.modifiedPreset) ?? anyTime(t)

      return {
        term: this.query.term.trim(),
        // A half-filled row would fail the whole request; drop it rather than
        // making the user delete it before searching.
        conditions: this.query.conditions.filter(usable),
        mimetypes: type.mimetypes,
        modifiedAfter: modifiedAfter(time) ?? undefined,
        matchAny: this.query.matchAny,
        sort: this.query.sort,
        descending: this.query.descending,
        limit,
        offset: Math.max(0, offset),
      }
    },

    /**
     * @param t translation function
     * @param offset row to start at
     * @param record whether to remember this as a recent search
     */
    async run(t: Translate, offset = 0, record = true) {
      if (!this.hasCriteria) {
        this.error = 'no-criteria'
        return
      }

      this.loading = true
      this.error = ''
      this.loadedAll = false
      try {
        const response = await SearchApi.search(this.request(t, offset, PAGE_SIZE))
        this.results = response.results
        this.hasMore = response.hasMore
        this.offset = response.offset
        this.searched = true
        if (record) {
          SearchApi.recordRecent({ ...this.query })
        }
      } catch (e) {
        this.error = (e as Error).message
        this.results = []
        this.hasMore = false
      } finally {
        this.loading = false
      }
    },

    /**
     * Pages through the whole result set. Grouping and any client-side work only
     * covers what is loaded, so a bounded "load all" is the honest way to make
     * them cover everything — bounded because the backend reports no total, and
     * an unbounded loop over someone's whole drive is not a feature.
     * @param t translation function
     */
    async loadAll(t: Translate) {
      if (!this.hasCriteria) {
        return
      }

      this.loading = true
      this.error = ''
      try {
        const all: FileResult[] = []
        let page = 0
        let more = true
        while (more && page < MAX_PAGES) {
          const response = await SearchApi.search(this.request(t, page * PAGE_SIZE, PAGE_SIZE))
          all.push(...response.results)
          more = response.hasMore
          page++
        }
        this.results = all
        this.hasMore = false
        this.offset = 0
        this.searched = true
        this.loadedAll = true
        if (more) {
          this.error = 'capped'
        }
      } catch (e) {
        this.error = (e as Error).message
      } finally {
        this.loading = false
      }
    },

    /**
     * Sorting is server-side, so it re-runs from the first page.
     * @param t
     * @param field
     */
    async sortBy(t: Translate, field: string) {
      if (this.query.sort === field) {
        this.query.descending = !this.query.descending
      } else {
        this.query.sort = field
        this.query.descending = true
      }
      await this.run(t, 0, false)
    },

    async toggleFavorite(file: FileResult) {
      const next = !file.favorite
      file.favorite = next // optimistic: the star should not lag the click
      try {
        await SearchApi.setFavorite(file.fileid, next)
      } catch (e) {
        file.favorite = !next
        this.error = (e as Error).message
      }
    },
  },
})

function usable(condition: Condition): boolean {
  return condition.value !== '' && condition.value !== null && condition.value !== undefined
}
