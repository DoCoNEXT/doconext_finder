/**
 * The file shown in the full-screen preview.
 *
 * A store rather than component state because the command that opens it lives
 * in two places — the row menu and the details panel — and the dialog itself
 * belongs to the app shell, above both.
 */
import { defineStore } from 'pinia'
import type { FileResult } from '../types/Search'

export const usePreviewStore = defineStore('preview', {
  state: () => ({ file: null as FileResult | null }),

  actions: {
    open(file: FileResult) {
      this.file = file
    },

    close() {
      this.file = null
    },
  },
})
