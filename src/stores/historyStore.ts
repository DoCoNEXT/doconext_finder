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
     * Both kinds in one list, most recently run first. Whether a search was
     * named is not what makes it the right guess — what someone just used is,
     * and the box is reached for straight after using it.
     * @param state
     */
    suggestions: (state): StoredSearch[] =>
      [...state.saved, ...state.recents].sort((a, b) => b.lastRun - a.lastRun),

    /**
     * A saved search by name, matched the way a person reads a name: trimmed
     * and regardless of case, so "Reports" and "reports " are the same one.
     * @param state
     */
    savedNamed: (state) => (name: string): StoredSearch | undefined => {
      const wanted = name.trim().toLocaleLowerCase()
      return state.saved.find((entry) => (entry.name ?? '').trim().toLocaleLowerCase() === wanted)
    },
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

    /**
     * Saves over an existing saved search rather than adding a second one
     * under the same name.
     * @param entry the saved search to overwrite
     * @param query what it should search for from now on
     */
    async overwrite(entry: StoredSearch, query: StoredSearch['query']) {
      await SearchApi.replaceQuery(entry.id, query)
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
     * Best-effort ordering bump; never worth failing a run over. The reload is
     * what the search box needs: it reads this list once on mount and orders
     * its suggestions on lastRun, so bumping only the server would leave the
     * search just run sitting wherever it was. Recents need no reload here —
     * their run goes through recordRecent, which reloads for the same reason.
     * @param entry the search that was just run
     */
    async markRun(entry: StoredSearch) {
      if (entry.kind !== 'saved') {
        return
      }
      await SearchApi.markRun(entry.id)
      await this.load(true)
    },
  },
})
