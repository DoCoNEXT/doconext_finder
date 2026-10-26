<template>
  <NcDialog :name="title"
            size="normal"
            @closing="emit('close', changed)">
    <div class="search-dialog">
      <NcTextField ref="nameField"
                   v-model="name"
                   :label="t('Name')"
                   :disabled="busy"
                   :error="nameProblem !== ''"
                   :helper-text="nameProblem"
                   @keydown.enter.prevent="submit" />

      <!--
      Said under the field rather than in a second dialog: choosing another
      name is just typing on, and replacing is the button below.
    -->
      <NcNoteCard v-if="clash" type="warning" class="search-dialog__note">
        {{ clashText }}
      </NcNoteCard>

      <NcTextArea v-model="description"
                  class="search-dialog__description"
                  :label="t('Description (optional)')"
                  :disabled="busy"
                  resize="vertical" />

      <!-- What is being named. Building a search and naming it are two steps;
         this is what the second one is about. -->
      <section v-if="query" class="search-dialog__section">
        <h4>{{ t('What you save') }}</h4>
        <p class="muted">{{ summary }}</p>
      </section>

      <section class="search-dialog__section">
        <h4>{{ t('Sharing') }}</h4>
        <p v-if="!canShare" class="muted">
          {{ t('Only {owner} can share this search.', { owner: ownerName }) }}
        </p>
        <template v-else>
          <p class="muted">
            {{ t('Whoever you share with runs the same search and sees only the files they can open themselves.') }}
          </p>
          <ShareRecipientsEditor :rows="rows"
                                 :disabled="busy || loadingShares"
                                 @add="add"
                                 @toggle="toggle"
                                 @remove="remove" />

          <!-- Only once there is a search to link to. -->
          <div v-if="linkTarget" class="search-dialog__link">
            <NcButton class="search-dialog__copy" variant="secondary" @click="copyLink">
              <template #icon>
                <LinkIcon :size="20" />
              </template>
              {{ t('Copy link') }}
            </NcButton>
            <span class="muted">{{ linkReach }}</span>
          </div>
        </template>
      </section>

      <NcNoteCard v-if="error" type="error">{{ error }}</NcNoteCard>
    </div>

    <template #actions>
      <NcButton variant="tertiary" :disabled="busy" @click="emit('close', changed)">
        {{ t('Cancel') }}
      </NcButton>
      <NcButton :variant="clash ? 'error' : 'primary'"
                :disabled="busy || nameProblem !== '' || name.trim() === ''"
                @click="submit">
        {{ submitLabel }}
      </NcButton>
    </template>
  </NcDialog>
</template>

<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import {
  NcButton,
  NcDialog,
  NcNoteCard,
  NcTextArea,
  NcTextField,
} from '@nextcloud/vue'
import { Link as LinkIcon } from '@lucide/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import ShareRecipientsEditor from './ShareRecipientsEditor.vue'
import { useClipboard } from '../composables/useClipboard'
import { useI18n } from '../composables/useI18n'
import { useHistoryStore } from '../stores/historyStore'
import { useSearchStore } from '../stores/searchStore'
import { SearchApi } from '../services/SearchApi'
import { searchLink } from '../services/links'
import { describeQuery } from '../filters/describe'
import type { SearchState, Sharee, StagedShare, StoredSearch } from '../types/Search'

/**
 * Naming a search, describing it and sharing it — in one place, whether the
 * search is being saved for the first time or was saved long ago.
 *
 * Opened two ways. With `query`, to save the search being built: a recent
 * kept, a shared one copied, or the one on the Search page. With `entry`, to
 * edit a saved search: its name, its description and, for its owner, who it
 * is shared with.
 *
 * Nothing is sent until Save. Sharing a search that does not exist yet would
 * have no search to point at, and the same rule for editing keeps one button
 * meaning one thing. Saving then sends only what changed.
 */
const props = defineProps<{
  /** The search to save, for a new saved search. */
  query?: SearchState
  /** The saved search to edit. */
  entry?: StoredSearch
  /** The name to offer for a new one. */
  suggestedName?: string
  /**
   * Only the user's own names count as taken — for a copy of a shared
   * search, which must never offer to replace the original.
   */
  ownOnly?: boolean
}>()

/** `true` when something was saved, so the opener can read its list again. */
const emit = defineEmits<{ (e: 'close', saved: boolean): void }>()

const { t } = useI18n()
const history = useHistoryStore()
const search = useSearchStore()
const { copy } = useClipboard()

/**
 * The saved search this dialog writes to. Set from the start when editing; set
 * after the first save when sharing it then failed for someone, so the dialog
 * stays on the search it just made instead of making a second one.
 */
const target = ref<StoredSearch | null>(props.entry ?? null)

const name = ref(props.entry?.name ?? props.suggestedName ?? '')
const description = ref(props.entry?.description ?? '')
const rows = ref<StagedShare[]>([])
/** Shares that existed and were taken off the list, to end on save. */
const removed = ref<StagedShare[]>([])
const loadingShares = ref(false)
const busy = ref(false)
const error = ref('')
/** Whether anything was written, even if the dialog is then cancelled. */
const changed = ref(false)
const nameField = ref<{ focus?: () => void, select?: () => void } | null>(null)

const editing = computed(() => target.value !== null)

const title = computed(() => (editing.value
  ? t('Edit "{name}"', { name: target.value?.name ?? '' })
  : t('Save search')))

/**
 * Another saved search with the name being typed. Saving a new one over it
 * replaces it; that is the only way to replace a saved search's query, for
 * one shared with the user too — if they may edit it.
 */
const clash = computed((): StoredSearch | undefined => {
  if (editing.value) {
    return undefined
  }
  return history.savedNamed(name.value, props.ownOnly)
})

const clashText = computed(() => {
  const found = clash.value
  if (!found) {
    return ''
  }
  return found.mine
    ? t('You already have a search called "{name}". Saving replaces it.', { name: found.name ?? '' })
    : t('"{name}" is a search {owner} shared with you. Saving replaces it for everyone it is shared with.', {
      name: found.name ?? '',
      owner: found.ownerDisplayName ?? found.owner ?? '',
    })
})

/**
 * When editing, a name another of the user's searches already has. Two saved
 * searches under one name could not be told apart in the list.
 */
const nameProblem = computed(() => {
  const wanted = name.value.trim().toLocaleLowerCase()
  if (!editing.value || wanted === '') {
    return ''
  }
  const other = history.saved.find((entry) => entry.id !== target.value?.id
    && (entry.name ?? '').trim().toLocaleLowerCase() === wanted)
  return other ? t('You already have a search with this name.') : ''
})

/** The search whose shares the dialog shows: the one edited, or the one replaced. */
const sharesOf = computed((): StoredSearch | null => target.value ?? (clash.value?.mine ? clash.value : null))

/** Only the owner shares; a search shared with the user, even editable, is not theirs to pass on. */
const canShare = computed(() => {
  const subject = target.value ?? clash.value
  return !subject || subject.mine
})

const ownerName = computed(() => {
  const subject = target.value ?? clash.value
  return subject?.ownerDisplayName ?? subject?.owner ?? ''
})

const linkTarget = computed(() => sharesOf.value)

/**
 * Who the link opens the search for. Said every time, because a link reads as
 * a way to share — and it is not one.
 */
const linkReach = computed(() => (rows.value.length === 0
  ? t('This link works only for you until you share the search with someone.')
  : t('Opens this search for you and for everyone above. Anyone else sees nothing.')))

const summary = computed(() => {
  if (!props.query) {
    return ''
  }
  const term = props.query.term?.trim() ?? ''
  const parts = [
    term !== '' ? t('“{term}”', { term }) : '',
    describeQuery(t, props.query, search.schema?.metadata),
  ].filter((part) => part !== '')
  return parts.length ? parts.join(' · ') : t('No filters')
})

const submitLabel = computed(() => {
  if (clash.value) {
    return t('Replace')
  }
  if (!editing.value && rows.value.length > 0) {
    return t('Save and share')
  }
  return t('Save')
})

onMounted(async () => {
  // The name check reads the saved list, which the Search page has no other
  // reason to have loaded.
  await history.load()
  await nextTick()
  nameField.value?.focus?.()
  nameField.value?.select?.()
})

// The shares on the list are those of the search the dialog would write to.
// Typing a name that replaces another search brings that search's shares in,
// so it is plain who already has it; the people picked here stay on.
watch(sharesOf, async (subject) => {
  const picked = rows.value.filter((row) => row.shareId === undefined)
  removed.value = []
  if (!subject || !subject.mine) {
    rows.value = picked
    return
  }
  loadingShares.value = true
  try {
    const existing = await SearchApi.shares(subject.id)
    rows.value = [
      ...existing.map((share): StagedShare => ({
        key: `${share.type}:${share.with}`,
        type: share.type,
        with: share.with,
        displayName: share.displayName,
        canEdit: share.canEdit,
        shareId: share.id,
        savedCanEdit: share.canEdit,
      })),
      ...picked.filter((row) => !existing.some((share) => share.type === row.type && share.with === row.with)),
    ]
  } catch (e) {
    error.value = (e as Error).message
  } finally {
    loadingShares.value = false
  }
}, { immediate: true })

/**
 * Read-only first: being able to change a search for everyone is the larger
 * thing to give, so it is a second, deliberate click.
 * @param sharee who was picked
 */
function add(sharee: Sharee) {
  rows.value.push({
    key: `${sharee.type}:${sharee.id}`,
    type: sharee.type,
    with: sharee.id,
    displayName: sharee.displayName,
    canEdit: false,
  })
}

/**
 * @param row the recipient
 * @param canEdit what they should be allowed
 */
function toggle(row: StagedShare, canEdit: boolean) {
  row.canEdit = canEdit
}

/**
 * @param row the recipient to take off the list
 */
function remove(row: StagedShare) {
  rows.value = rows.value.filter((other) => other.key !== row.key)
  if (row.shareId !== undefined) {
    removed.value.push(row)
  }
}

async function copyLink() {
  if (!linkTarget.value) {
    return
  }
  if (await copy(searchLink(linkTarget.value.id))) {
    showSuccess(t('Link copied'))
  } else {
    showError(t('The link could not be copied'))
  }
}

async function submit() {
  const trimmed = name.value.trim()
  if (busy.value || trimmed === '' || nameProblem.value !== '') {
    return
  }
  busy.value = true
  error.value = ''
  // Worded now: once the list is read again the search just saved is itself
  // a search with this name, and would read as replaced.
  const message = savedMessage(trimmed)
  try {
    const saved = await writeSearch(trimmed)
    changed.value = true
    const failures = canShare.value ? await writeShares(saved.id) : []
    await history.load(true)

    if (failures.length === 0) {
      showSuccess(message)
      emit('close', true)
      return
    }

    // The search exists now. Stay on it, so trying again shares it rather
    // than saving it a second time, and say which shares did not go through.
    target.value = history.saved.find((entry) => entry.id === saved.id) ?? saved
    error.value = t('The search was saved, but sharing it failed for: {names}', {
      names: failures.join(', '),
    })
  } catch (e) {
    error.value = (e as Error).message
  } finally {
    busy.value = false
  }
}

/**
 * Saves the search itself: a new one, the one it replaces, or the one edited.
 * @param trimmed the name to save it under
 */
async function writeSearch(trimmed: string): Promise<StoredSearch> {
  if (target.value) {
    return SearchApi.rename(target.value.id, trimmed, description.value)
  }
  if (clash.value && props.query) {
    const replaced = await SearchApi.replaceQuery(clash.value.id, { ...props.query })
    // A description left empty here keeps the one it had: replacing a search
    // changes what it finds, not what its owner wrote about it.
    return description.value.trim() !== ''
      ? SearchApi.rename(replaced.id, trimmed, description.value)
      : replaced
  }
  return SearchApi.save(trimmed, description.value, { ...props.query } as SearchState)
}

/**
 * Sends what changed in the list. Each share is its own request, and one that
 * fails does not stop the rest.
 * @param searchId the saved search
 * @return the names of those it failed for
 */
async function writeShares(searchId: number): Promise<string[]> {
  const failed: string[] = []
  const attempt = async (row: StagedShare, send: () => Promise<unknown>) => {
    try {
      await send()
    } catch (e) {
      failed.push(`${row.displayName} (${(e as Error).message})`)
    }
  }

  for (const row of removed.value) {
    await attempt(row, () => SearchApi.unshare(searchId, row.shareId as number))
  }
  for (const row of rows.value) {
    if (row.shareId === undefined) {
      await attempt(row, () => SearchApi.share(searchId, row.type, row.with, row.canEdit))
    } else if (row.canEdit !== row.savedCanEdit) {
      await attempt(row, () => SearchApi.updateShare(searchId, row.shareId as number, row.canEdit))
    }
  }

  return failed
}

/**
 * @param trimmed the name it was saved under
 */
function savedMessage(trimmed: string): string {
  if (editing.value) {
    return t('Saved "{name}"', { name: trimmed })
  }
  if (clash.value) {
    return t('Replaced "{name}"', { name: trimmed })
  }
  return rows.value.length > 0
    ? t('Saved and shared "{name}"', { name: trimmed })
    : t('Saved "{name}"', { name: trimmed })
}
</script>

<style scoped lang="scss">
// Not `.dialog`: NcDialog names its own parts dialog__content, dialog__wrapper…
.search-dialog {
  &__note {
    margin: 4px 0 0;
  }

  // Nested on purpose. NcTextArea gives its own root a top margin, and a
  // single scoped class here only ties with that rule — load order then
  // decided, and its 6px won: the label, which floats above the field's
  // border, sat on the name field above it.
  & &__description {
    margin-top: 16px;
  }

  &__section {
    margin-top: 16px;

    h4 {
      margin: 0 0 4px;
      font-weight: 700;
    }

    p {
      margin: 0 0 4px;
    }
  }

  &__link {
    display: flex;
    align-items: center;
    gap: 12px;
    margin: 12px 0 4px;
  }

  // Its label is short and must read whole; the sentence beside it wraps.
  &__copy {
    flex: none;
  }
}
</style>
