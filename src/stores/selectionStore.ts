/**
 * The row whose details are shown.
 *
 * Lives in a store because the sidebar cannot live where the selection is made:
 * NcAppSidebar has to be a sibling of NcAppContent, so it is rendered by the app
 * shell while the selecting happens inside a page.
 *
 * Selection says *which* row is described, never *whether* the panel is there:
 * that is `preferences.sidebarPinned`, a view option like the columns. Keeping
 * the two apart is the whole point — a click highlights a row whether the panel
 * is open or not, and the panel opens and closes only when you ask it to.
 */
import { defineStore } from 'pinia'
import type { FileResult } from '../types/Search'

export const useSelectionStore = defineStore('selection', {
  state: () => ({ file: null as FileResult | null }),

  actions: {
    /**
     * @param file the row that was clicked
     */
    select(file: FileResult) {
      this.file = file
    },

    clear() {
      this.file = null
    },

    /**
     * A new result set invalidates the selection: the panel would otherwise keep
     * describing a file that is no longer on screen. The panel itself stays —
     * it waits, empty, for the first click rather than jumping away or picking a
     * row nobody chose.
     */
    onResults() {
      this.file = null
    },
  },
})
