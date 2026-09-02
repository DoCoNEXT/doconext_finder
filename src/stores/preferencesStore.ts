/**
 * Grid columns and grouping, shared by every page that shows results.
 *
 * Saving is fire-and-forget and adopts whatever the server returns: it drops
 * unknown columns and appends missing ones, so taking its answer keeps the two
 * sides from quietly disagreeing.
 */
import { defineStore } from 'pinia'
import { SearchApi } from '../services/SearchApi'
import type { ColumnPref, Preferences } from '../types/Search'

interface State {
  columns: ColumnPref[]
  grouping: string
  loaded: boolean
}

export const usePreferencesStore = defineStore('preferences', {
  state: (): State => ({ columns: [], grouping: '', loaded: false }),

  getters: {
    visibleColumns: (state): ColumnPref[] => state.columns.filter((c) => c.visible),
  },

  actions: {
    async load() {
      if (this.loaded) {
        return
      }
      try {
        this.adopt(await SearchApi.preferences())
      } catch {
        // A preferences failure must not stop the app; the grid falls back to
        // whatever defaults the components carry.
      }
    },

    adopt(preferences: Preferences) {
      this.columns = preferences.columns
      this.grouping = preferences.grouping
      this.loaded = true
    },

    async save() {
      try {
        this.adopt(await SearchApi.savePreferences({
          columns: this.columns,
          grouping: this.grouping,
        }))
      } catch {
        // ignored on purpose — see above
      }
    },

    toggle(id: string) {
      const column = this.columns.find((c) => c.id === id)
      // Name identifies the row; hiding it would leave an unreadable grid, and
      // the server refuses it anyway.
      if (!column || id === 'name') {
        return
      }
      column.visible = !column.visible
      this.save()
    },

    rename(id: string, label: string) {
      const column = this.columns.find((c) => c.id === id)
      if (column) {
        column.label = label.trim()
        this.save()
      }
    },

    move(id: string, direction: -1 | 1) {
      const from = this.columns.findIndex((c) => c.id === id)
      const to = from + direction
      if (from === -1 || to < 0 || to >= this.columns.length) {
        return
      }
      const [column] = this.columns.splice(from, 1)
      this.columns.splice(to, 0, column!)
      this.save()
    },

    setGrouping(grouping: string) {
      this.grouping = grouping
      this.save()
    },

    async reset() {
      this.columns = []
      this.grouping = ''
      // An empty column list makes the server rebuild the defaults and hand
      // them back, so the defaults live in one place.
      await this.save()
    },
  },
})
