/**
 * The live search: its interface state, its results, and how to run it.
 *
 * Held in a store rather than in the search view because other pages hand
 * searches to it — running a saved search switches to Search with that query
 * already loaded — and because Favorites reuses the same result plumbing.
 */
import { defineStore } from 'pinia'
import { SearchApi } from '../services/SearchApi'
import { useHistoryStore } from './historyStore'
import { usePreferencesStore } from './preferencesStore'
import { anyTime, anyType, fileTypePresets, modifiedAfter, modifiedPresets } from '../filters/presets'
import type { Translate } from '../filters/presets'
import type { Condition, FieldsResponse, FileResult, SearchState } from '../types/Search'

/**
 * How many rows "Load all" will fetch before stopping. A row cap rather than a
 * page cap, because the page size is now the user's to choose — capping pages
 * would quietly turn a larger page size into an eightfold bigger load.
 */
const MAX_LOADED_ROWS = 2000

export function emptyState(): SearchState {
  return {
    term: '',
    content: '',
    typePreset: 'any',
    customType: null,
    modifiedPreset: 'any',
    conditions: [],
    matchAny: false,
    sort: 'mtime',
    descending: true,
    scope: null,
  }
}

interface State {
  query: SearchState
  /**
   * The words the search that produced these results looked for, for the
   * preview to mark in the document. Held apart from `query`, which changes
   * with every keystroke in the search box: the marks belong to the search that
   * ran, not to the one being typed.
   */
  highlight: string
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
    highlight: '',
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
    /** The user's setting, so it applies to paging and to Load all alike. */
    pageSize: (): number => usePreferencesStore().pageSize,

    /**
     * True when there is anything to search by — a blank search is refused.
     * @param state
     */
    hasCriteria(state): boolean {
      return state.query.term.trim() !== ''
        || state.query.content.trim() !== ''
        || state.query.conditions.length > 0
        || state.query.typePreset !== 'any'
        || state.query.modifiedPreset !== 'any'
        // "Everything in this dossier" is a search worth running on its own.
        || state.query.scope !== null
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

    /** A fresh search starts from the user's default sort. */
    reset() {
      const preferences = usePreferencesStore()
      this.query = { ...emptyState(), sort: preferences.sort, descending: preferences.descending }
      this.highlight = ''
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
      // The distilled type wins when it is the one selected: it exists precisely
      // because no configured filter says what the question asked for.
      const type = this.query.customType?.id === this.query.typePreset
        ? this.query.customType
        : fileTypePresets(t).find((p) => p.id === this.query.typePreset) ?? anyType(t)
      const time = modifiedPresets(t).find((p) => p.id === this.query.modifiedPreset) ?? anyTime(t)

      return {
        term: this.query.term.trim(),
        content: this.query.content.trim(),
        // A half-filled row would fail the whole request; drop it rather than
        // making the user delete it before searching. The display label goes no
        // further than this app, for the reason the scope's does not either.
        conditions: this.query.conditions.filter(usable).map(sendable),
        mimetypes: type.mimetypes,
        modifiedAfter: modifiedAfter(time) ?? undefined,
        // Only the level and the id travel; the label is ours to display, and
        // sending it back would invite the server to trust a client's wording.
        scope: this.query.scope
          ? { level: this.query.scope.level, id: this.query.scope.id }
          : undefined,
        matchAny: this.query.matchAny,
        // Relevance is the index's ordering, so it means nothing without a
        // content term. The server drops it too; sending mtime instead keeps
        // the request honest about what it will get back.
        sort: this.query.sort === 'relevance' && this.query.content.trim() === ''
          ? 'mtime'
          : this.query.sort,
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
        const response = await SearchApi.search(this.request(t, offset, this.pageSize))
        this.results = response.results
        this.hasMore = response.hasMore
        this.offset = response.offset
        this.searched = true
        this.highlight = searchedWords(this.query)
        if (response.truncated) {
          this.error = 'scope-truncated'
        }
        if (record) {
          // Not awaited: the results are already on screen and this only keeps
          // the history in step. Reloading it matters because the search box
          // orders its suggestions on when each search last ran, and it reads
          // that list once on mount — without this the search just run would
          // stay wherever it was until the page was left and come back.
          SearchApi.recordRecent({ ...this.query })
            .then(() => useHistoryStore().load(true))
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
        let more = true
        while (more && all.length < MAX_LOADED_ROWS) {
          const response = await SearchApi.search(this.request(t, all.length, this.pageSize))
          all.push(...response.results)
          more = response.hasMore
          if (response.results.length === 0) {
            break // defensive: never spin on a page that returns nothing
          }
        }
        this.results = all
        this.hasMore = false
        this.offset = 0
        this.searched = true
        this.loadedAll = true
        this.highlight = searchedWords(this.query)
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

/**
 * What a search asked for in words, for a preview to mark.
 *
 * Both text fields, not only the content one: a name term is regularly in the
 * document as well — a case number typed into "Name" is exactly what you want
 * lit up once the file is open — and marking a word that is not there costs
 * nothing.
 * @param query the search that ran
 */
function searchedWords(query: SearchState): string {
  return [query.content.trim(), query.term.trim()].filter(Boolean).join(' ')
}

/**
 * The condition as the server should see it: the display label is this app's
 * business, for the reason the scope's label is too.
 * @param condition the row to send
 */
function sendable(condition: Condition): Omit<Condition, 'label'> {
  const sent = { ...condition }
  delete sent.label

  return sent
}

function usable(condition: Condition): boolean {
  return condition.value !== '' && condition.value !== null && condition.value !== undefined
}
