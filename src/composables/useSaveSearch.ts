/**
 * Saving a search under a name, from wherever a search can be saved: the
 * Search page saves the one you are building, the Searches page saves a recent
 * you want to keep or a copy of one shared with you. All of them open the same
 * dialog, which also asks what happens when the name is already taken and who
 * to share the search with.
 */
import { spawnDialog } from '@nextcloud/vue/functions/dialog'
import SearchDialog from '../components/SearchDialog.vue'
import type { SearchState } from '../types/Search'

export function useSaveSearch() {
  /**
   * @param query the search to keep
   * @param suggested the name to offer first
   * @param ownOnly only the user's own names count as taken — for a copy of a
   *        shared search, which must never offer to replace the original
   * @return true when something was saved, false when the user backed out
   */
  async function saveSearch(query: SearchState, suggested: string, ownOnly = false): Promise<boolean> {
    const saved = await spawnDialog(SearchDialog, { query: { ...query }, suggestedName: suggested, ownOnly })
    return saved === true
  }

  return { saveSearch }
}
