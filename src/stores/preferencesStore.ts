/**
 * Grid columns and grouping, shared by every page that shows results.
 *
 * Saving is fire-and-forget and adopts whatever the server returns: it drops
 * unknown columns and appends missing ones, so taking its answer keeps the two
 * sides from quietly disagreeing.
 */
import { defineStore } from 'pinia'
import { showError } from '@nextcloud/dialogs'
import { SearchApi } from '../services/SearchApi'
import { useI18n } from '../composables/useI18n'
import type { ColumnPref, GroupScope, Preferences } from '../types/Search'

/**
 * A copy that shares nothing with the store: the columns are edited in place,
 * so a snapshot holding the same arrays would change along with them. JSON
 * rather than structuredClone, which refuses the store's reactive proxies.
 * @param preferences what to copy
 */
function detached(preferences: Preferences): Preferences {
  return JSON.parse(JSON.stringify(preferences)) as Preferences
}

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
  /** Ordered grouping levels for the search results, outermost first. */
  grouping: string[]
  /** The same, for the favorites list. */
  favoritesGrouping: string[]
  pageSize: number
  sort: string
  descending: boolean
  doubleClick: string
  /** Whether the details panel is on screen; see `Preferences.sidebarPinned`. */
  sidebarPinned: boolean
  /** Colour for the marked search terms in a preview; '' for the default. */
  highlightColor: string
  loaded: boolean
  /**
   * What the server last confirmed, so a save that fails can put the screen
   * back to it — otherwise the page shows a setting the server never kept,
   * and the next reload quietly takes it away.
   */
  stored: Preferences | null
}

export const usePreferencesStore = defineStore('preferences', {
  state: (): State => ({
    columns: [],
    grouping: [],
    favoritesGrouping: [],
    pageSize: 50,
    sort: 'mtime',
    descending: true,
    doubleClick: 'open',
    sidebarPinned: false,
    highlightColor: '',
    loaded: false,
    revision: 0,
    stored: null,
  }),

  getters: {
    visibleColumns: (state): ColumnPref[] => state.columns.filter((c) => c.visible),

    /**
     * The grouping levels of one result list. Search and Favorites are separate
     * pages showing different sets, so they keep separate levels — grouping the
     * search by "Type" should not silently regroup the favorites too.
     * @param state
     */
    groupingFor: (state) => (scope: GroupScope): string[] =>
      (scope === 'favorites' ? state.favoritesGrouping : state.grouping),
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
      this.favoritesGrouping = preferences.favoritesGrouping ?? []
      this.pageSize = preferences.pageSize
      this.sort = preferences.sort
      this.descending = preferences.descending
      this.doubleClick = preferences.doubleClick
      this.sidebarPinned = preferences.sidebarPinned
      this.highlightColor = preferences.highlightColor ?? ''
      this.loaded = true
      this.stored = detached(preferences)
    },

    async save() {
      const revision = ++this.revision
      try {
        const stored = await SearchApi.savePreferences({
          columns: this.columns,
          grouping: this.grouping,
          favoritesGrouping: this.favoritesGrouping,
          pageSize: this.pageSize,
          sort: this.sort,
          descending: this.descending,
          doubleClick: this.doubleClick,
          sidebarPinned: this.sidebarPinned,
          highlightColor: this.highlightColor,
        })
        // Adopt only while this is still the newest save in flight; a later edit
        // has already sent its own, whose response is the one that counts.
        if (revision === this.revision) {
          this.adopt(stored)
        }
      } catch {
        // Unlike a failed load, a failed save is something the user did and
        // has to hear about: the change is undone on screen, so what they see
        // is what the server keeps.
        if (revision === this.revision) {
          if (this.stored) {
            this.adopt(detached(this.stored))
          }
          showError(useI18n().t('Your settings could not be saved. Please try again.'))
        }
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

    /**
     * Name stays first — the grid pins it while the rest scrolls sideways, and
     * the server puts it back there anyway — so it neither moves nor is passed.
     * @param id
     * @param direction
     */
    move(id: string, direction: -1 | 1) {
      const from = this.columns.findIndex((c) => c.id === id)
      const to = from + direction
      if (id === 'name' || from === -1 || to < 1 || to >= this.columns.length) {
        return
      }
      const [column] = this.columns.splice(from, 1)
      this.columns.splice(to, 0, column!)
      this.save()
    },

    /**
     * The levels of one scope, as a mutable array.
     * @param scope which result list
     */
    levels(scope: GroupScope): string[] {
      return scope === 'favorites' ? this.favoritesGrouping : this.grouping
    },

    addGrouping(scope: GroupScope, level: string) {
      const levels = this.levels(scope)
      if (!level || levels.includes(level) || levels.length >= MAX_GROUPING_LEVELS) {
        return
      }
      levels.push(level)
      this.save()
    },

    removeGrouping(scope: GroupScope, level: string) {
      const kept = this.levels(scope).filter((g) => g !== level)
      if (scope === 'favorites') {
        this.favoritesGrouping = kept
      } else {
        this.grouping = kept
      }
      this.save()
    },

    /**
     * Order matters: the levels nest outermost first.
     * @param scope which result list
     * @param level
     * @param direction
     */
    moveGrouping(scope: GroupScope, level: string, direction: -1 | 1) {
      const levels = this.levels(scope)
      const from = levels.indexOf(level)
      const to = from + direction
      if (from === -1 || to < 0 || to >= levels.length) {
        return
      }
      const [moved] = levels.splice(from, 1)
      levels.splice(to, 0, moved!)
      this.save()
    },

    clearGrouping(scope: GroupScope) {
      if (scope === 'favorites') {
        this.favoritesGrouping = []
      } else {
        this.grouping = []
      }
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

    /**
     * Shows or hides the details panel. Remembered, so the app comes back the
     * way you left it.
     * @param pinned whether the panel is on screen
     */
    setSidebarPinned(pinned: boolean) {
      this.sidebarPinned = pinned
      this.save()
    },

    /**
     * The colour the search terms are marked in inside a preview.
     * @param colour `#rrggbb`, or '' to go back to the preview's own default
     */
    setHighlightColor(colour: string) {
      this.highlightColor = colour
      this.save()
    },

    async reset() {
      this.columns = []
      this.grouping = []
      this.favoritesGrouping = []
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
