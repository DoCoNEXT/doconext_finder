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
    select(file: FileResult) {
      // Clicking the selected row again closes the panel.
      this.file = this.file?.fileid === file.fileid ? null : file
    },

    clear() {
      this.file = null
    },
  },
})
