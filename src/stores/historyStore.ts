/**
 * Saved searches and recents.
 *
 * A store because two places need the same list: the Searches page, and the
 * suggestions the search box drops down. Loading it twice would let them
 * disagree the moment one of them saved something.
 */
import { defineStore } from 'pinia'
import { SearchApi } from '../services/SearchApi'
import type { StoredSearch } from '../types/Search'

interface State {
  saved: StoredSearch[]
  recents: StoredSearch[]
  loading: boolean
  loaded: boolean
  error: string
}

export const useHistoryStore = defineStore('history', {
  state: (): State => ({
    saved: [],
    recents: [],
    loading: false,
    loaded: false,
    error: '',
  }),

  getters: {
    /**
     * Saved first: a search someone named is a better guess than one they ran
     * once.
     * @param state
     */
    suggestions: (state): StoredSearch[] => [...state.saved, ...state.recents],
  },

  actions: {
    /**
     * @param force reload even when the list is already in hand
     */
    async load(force = false) {
      if (this.loaded && !force) {
        return
      }
      this.loading = true
      this.error = ''
      try {
        const history = await SearchApi.history()
        this.saved = history.saved
        this.recents = history.recents
        this.loaded = true
      } catch (e) {
        this.error = (e as Error).message
      } finally {
        this.loading = false
      }
    },

    async save(name: string, description: string, query: StoredSearch['query']) {
      await SearchApi.save(name, description, query)
      await this.load(true)
    },

    async rename(entry: StoredSearch, name: string, description: string) {
      await SearchApi.rename(entry.id, name, description)
      await this.load(true)
    },

    async remove(entry: StoredSearch) {
      await SearchApi.remove(entry.id)
      await this.load(true)
    },

    async clearRecents() {
      await SearchApi.clearRecents()
      await this.load(true)
    },

    /**
     * Best-effort ordering bump; never worth failing a run over.
     * @param entry the search that was just run
     */
    markRun(entry: StoredSearch) {
      if (entry.kind === 'saved') {
        SearchApi.markRun(entry.id)
      }
    },
  },
})
