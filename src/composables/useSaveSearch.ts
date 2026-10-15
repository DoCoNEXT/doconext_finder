/**
 * Saving a search under a name, from wherever a search can be saved: the
 * Search page saves the one you are building, the Searches page saves a recent
 * you want to keep. Both have to answer the same question — what happens when
 * that name is already taken — so both ask it from here.
 */
import { getDialogBuilder, showSuccess } from '@nextcloud/dialogs'
import { useI18n } from './useI18n'
import { useHistoryStore } from '../stores/historyStore'
import type { SearchState, StoredSearch } from '../types/Search'

type Answer = 'replace' | 'rename' | 'cancel'

export function useSaveSearch() {
  const { t } = useI18n()
  const history = useHistoryStore()

  /**
   * Asks for a name and saves the query under it. A name already in use is not
   * silently doubled up: saving twice under one name used to leave two entries
   * you could not tell apart in the list.
   *
   * @param query the search to keep
   * @param suggested the name to offer first
   * @param ownOnly only the user's own names count as taken — for a copy of a
   *        shared search, which must never offer to replace the original
   * @return true when something was saved, false when the user backed out
   */
  async function saveSearch(query: SearchState, suggested: string, ownOnly = false): Promise<boolean> {
    // The duplicate check reads the saved list, which the Search page has no
    // other reason to have loaded.
    await history.load()

    let proposal = suggested
    for (;;) {
      const name = window.prompt(t('Name this search'), proposal)
      if (name === null) {
        return false
      }

      const trimmed = name.trim()
      if (trimmed === '') {
        proposal = suggested
        continue // an unnamed saved search is unfindable; ask again
      }

      const existing = history.savedNamed(trimmed, ownOnly)
      if (!existing) {
        await history.save(trimmed, '', { ...query })
        showSuccess(t('Saved "{name}"', { name: trimmed }))
        return true
      }

      const answer = await askAboutName(existing)
      if (answer === 'cancel') {
        return false
      }
      if (answer === 'replace') {
        await history.overwrite(existing, { ...query })
        showSuccess(t('Replaced "{name}"', { name: trimmed }))
        return true
      }
      // 'rename': back to the prompt, with the taken name to edit rather than
      // the original suggestion, which is what they already rejected.
      proposal = trimmed
    }
  }

  /**
   * Replace, pick another name, or give up. Closing the dialog is giving up,
   * which is why the promise's rejection is an answer rather than an error.
   * @param existing the saved search that already has the name
   */
  async function askAboutName(existing: StoredSearch): Promise<Answer> {
    let answer: Answer = 'cancel'
    const name = existing.name ?? ''

    // Replacing one that was shared with you changes it for its owner and for
    // everyone else it is shared with; that is worth saying before, not after.
    const text = existing.mine
      ? t('A saved search called "{name}" already exists. Replace it, or keep both under different names?', { name })
      : t('"{name}" is a search {owner} shared with you. Replacing it changes it for everyone it is shared with. Replace it, or save yours under a different name?', {
        name,
        owner: existing.ownerDisplayName ?? existing.owner ?? '',
      })

    const dialog = getDialogBuilder(t('This name is taken'))
      .setText(text)
      .setSeverity('warning')
      .addButton({
        label: t('Cancel'),
        variant: 'tertiary',
        callback: () => { answer = 'cancel' },
      })
      .addButton({
        label: t('Choose another name'),
        variant: 'secondary',
        callback: () => { answer = 'rename' },
      })
      .addButton({
        label: t('Replace'),
        variant: 'error',
        callback: () => { answer = 'replace' },
      })
      .build()

    await dialog.show().catch(() => { answer = 'cancel' })

    return answer
  }

  return { saveSearch }
}
