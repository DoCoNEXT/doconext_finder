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

/** Mirrors the server's cap; offering more levels would silently drop them. */
export const MAX_GROUPING_LEVELS = 4

interface State {
  columns: ColumnPref[]
  /**
   * Counts saves so a slow response cannot undo a newer edit. Two quick clicks
   * would otherwise race: the first response arrives after the second change and
   * adopting it reverts that change on screen until the second response lands.
   */
  revision: number
  /** Ordered grouping levels, outermost first. */
  grouping: string[]
  pageSize: number
  sort: string
  descending: boolean
  doubleClick: string
  sidebarPinned: boolean
  loaded: boolean
}

export const usePreferencesStore = defineStore('preferences', {
  state: (): State => ({
    columns: [],
    grouping: [],
    pageSize: 50,
    sort: 'mtime',
    descending: true,
    doubleClick: 'open',
    sidebarPinned: false,
    loaded: false,
    revision: 0,
  }),

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
      this.pageSize = preferences.pageSize
      this.sort = preferences.sort
      this.descending = preferences.descending
      this.doubleClick = preferences.doubleClick
      this.sidebarPinned = preferences.sidebarPinned
      this.loaded = true
    },

    async save() {
      const revision = ++this.revision
      try {
        const stored = await SearchApi.savePreferences({
          columns: this.columns,
          grouping: this.grouping,
          pageSize: this.pageSize,
          sort: this.sort,
          descending: this.descending,
          doubleClick: this.doubleClick,
          sidebarPinned: this.sidebarPinned,
        })
        // Adopt only while this is still the newest save in flight; a later edit
        // has already sent its own, whose response is the one that counts.
        if (revision === this.revision) {
          this.adopt(stored)
        }
      } catch {
        // ignored on purpose — see above
      }
    },

    /**
     * Appends a metadata column, visible, at the end of the grid.
     * @param id
     */
    addColumn(id: string) {
      if (this.columns.some((c) => c.id === id)) {
        return
      }
      this.columns.push({ id, visible: true, label: '' })
      this.save()
    },

    /**
     * Metadata columns can be removed outright; built-ins only hidden.
     * @param id
     */
    remove(id: string) {
      this.columns = this.columns.filter((c) => c.id !== id)
      this.save()
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

    addGrouping(level: string) {
      if (!level || this.grouping.includes(level) || this.grouping.length >= MAX_GROUPING_LEVELS) {
        return
      }
      this.grouping.push(level)
      this.save()
    },

    removeGrouping(level: string) {
      this.grouping = this.grouping.filter((g) => g !== level)
      this.save()
    },

    /**
     * Order matters: the levels nest outermost first.
     * @param level
     * @param direction
     */
    moveGrouping(level: string, direction: -1 | 1) {
      const from = this.grouping.indexOf(level)
      const to = from + direction
      if (from === -1 || to < 0 || to >= this.grouping.length) {
        return
      }
      const [moved] = this.grouping.splice(from, 1)
      this.grouping.splice(to, 0, moved!)
      this.save()
    },

    clearGrouping() {
      this.grouping = []
      this.save()
    },

    setPageSize(pageSize: number) {
      this.pageSize = pageSize
      this.save()
    },

    /**
     * The sort a new search starts from; the grid still sorts per search.
     * @param sort
     * @param descending
     */
    setDefaultSort(sort: string, descending: boolean) {
      this.sort = sort
      this.descending = descending
      this.save()
    },

    setDoubleClick(action: string) {
      this.doubleClick = action
      this.save()
    },

    setSidebarPinned(pinned: boolean) {
      this.sidebarPinned = pinned
      this.save()
    },

    async reset() {
      this.columns = []
      this.grouping = []
      this.pageSize = 50
      this.sort = 'mtime'
      this.descending = true
      this.doubleClick = 'open'
      this.sidebarPinned = false
      // An empty column list makes the server rebuild the defaults and hand
      // them back, so the defaults live in one place.
      await this.save()
    },
  },
})
