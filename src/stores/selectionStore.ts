/**
 * The row whose details are shown.
 *
 * Lives in a store because the sidebar cannot live where the selection is made:
 * NcAppSidebar has to be a sibling of NcAppContent, so it is rendered by the app
 * shell while the selecting happens inside a page.
 */
import { defineStore } from 'pinia'
import type { FileResult } from '../types/Search'

export const useSelectionStore = defineStore('selection', {
  state: () => ({ file: null as FileResult | null }),

  actions: {
    /**
     * @param file the row that was clicked
     * @param pinned when pinned, the panel stays open and simply follows the
     *   selection; unpinned, clicking the open row again closes it
     */
    select(file: FileResult, pinned = false) {
      this.file = !pinned && this.file?.fileid === file.fileid ? null : file
    },

    clear() {
      this.file = null
    },

    /**
     * A new result set invalidates the selection. Pinned, the panel stays and
     * takes the first row, so it never sits empty next to a full grid.
     * @param files the new result rows
     * @param pinned whether the panel is pinned open
     */
    onResults(files: FileResult[], pinned: boolean) {
      this.file = pinned ? files[0] ?? null : null
    },
  },
})
