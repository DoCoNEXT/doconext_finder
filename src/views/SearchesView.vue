<template>
  <div class="history">
    <div class="history__header">
      <h3>{{ t('Searches') }}</h3>
      <p class="muted">
        {{ t('Click to select, double-click or use Run search to execute one.') }}
      </p>
    </div>

    <NcNoteCard v-if="error" type="error">{{ error }}</NcNoteCard>
    <NcLoadingIcon v-if="history.loading" :size="28" class="history__loading" />

    <!--
      The two lists sit next to each other rather than stacked: they are the
      same kind of thing looked at two ways, and stacked they pushed Recent —
      the list you reach for most — below the fold. Each column scrolls on its
      own so a long Saved list cannot bury Recent.
    -->
    <div v-else class="history__columns">
      <section class="history__column">
        <!--
          The same two icons the search box's dropdown marks its suggestions
          with, so a row there and the list it came from read as one thing.
        -->
        <div class="history__heading">
          <Save :size="20" />
          <h4>{{ t('Saved') }}</h4>
          <span v-if="history.saved.length" class="muted">{{ history.saved.length }}</span>
        </div>

        <div class="history__body">
          <NcEmptyContent v-if="history.saved.length === 0"
                          :name="t('No saved searches yet')"
                          :description="t('Run a search, then use Save to keep it here.')">
            <template #icon>
              <Save />
            </template>
          </NcEmptyContent>

          <ul v-else class="history__list">
            <li v-for="entry in history.saved"
                :key="entry.id"
                :class="['history__item', { 'history__item--selected': isSelected(entry) }]">
              <button class="history__run"
                      @click="select(entry)"
                      @dblclick="run(entry)">
                <strong>{{ entry.name }}</strong>
                <span class="muted">{{ describe(entry) }}</span>
                <span v-if="entry.description" class="muted">{{ entry.description }}</span>
              </button>
              <NcActions :aria-label="t('Actions')">
                <NcActionButton @click="run(entry)">
                  <template #icon>
                    <Play :size="20" />
                  </template>
                  {{ t('Run search') }}
                </NcActionButton>
                <NcActionButton @click="startRename(entry)">
                  <template #icon>
                    <Pencil :size="20" />
                  </template>
                  {{ t('Rename') }}
                </NcActionButton>
                <NcActionButton @click="remove(entry)">
                  <template #icon>
                    <Trash2 :size="20" />
                  </template>
                  {{ t('Delete') }}
                </NcActionButton>
              </NcActions>
            </li>
          </ul>
        </div>
      </section>

      <section class="history__column">
        <div class="history__heading">
          <RotateCcwClock :size="20" />
          <h4>{{ t('Recent') }}</h4>
          <span v-if="history.recents.length" class="muted">{{ history.recents.length }}</span>
          <NcButton v-if="history.recents.length"
                    class="history__clear"
                    variant="secondary"
                    @click="clearRecents">
            {{ t('Clear') }}
          </NcButton>
        </div>

        <div class="history__body">
          <NcEmptyContent v-if="history.recents.length === 0"
                          :name="t('No recent searches')"
                          :description="t('Searches you run are listed here.')">
            <template #icon>
              <RotateCcwClock />
            </template>
          </NcEmptyContent>

          <ul v-else class="history__list">
            <li v-for="entry in history.recents"
                :key="entry.id"
                :class="['history__item', { 'history__item--selected': isSelected(entry) }]">
              <button class="history__run"
                      @click="select(entry)"
                      @dblclick="run(entry)">
                <strong>{{ entry.query.term || t('(no search term)') }}</strong>
                <span class="muted">{{ describe(entry) }}</span>
              </button>
              <NcActions :aria-label="t('Actions')">
                <NcActionButton @click="run(entry)">
                  <template #icon>
                    <Play :size="20" />
                  </template>
                  {{ t('Run search') }}
                </NcActionButton>
                <NcActionButton @click="keep(entry)">
                  <template #icon>
                    <Save :size="20" />
                  </template>
                  {{ t('Save this search') }}
                </NcActionButton>
                <NcActionButton @click="remove(entry)">
                  <template #icon>
                    <Trash2 :size="20" />
                  </template>
                  {{ t('Delete') }}
                </NcActionButton>
              </NcActions>
            </li>
          </ul>
        </div>
      </section>
    </div>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { showConfirmation } from '@nextcloud/dialogs'
import {
  NcActionButton,
  NcActions,
  NcButton,
  NcEmptyContent,
  NcLoadingIcon,
  NcNoteCard,
} from '@nextcloud/vue'
import {
  Pencil,
  Play,
  RotateCcwClock,
  Save,
  Trash2,
} from '@lucide/vue'
import { useI18n } from '../composables/useI18n'
import { useHistoryStore } from '../stores/historyStore'
import { useSaveSearch } from '../composables/useSaveSearch'
import { describeQuery } from '../filters/describe'
import type { StoredSearch } from '../types/Search'

const { t } = useI18n()
const history = useHistoryStore()
const { saveSearch } = useSaveSearch()

const emit = defineEmits<{ (e: 'run', entry: StoredSearch): void }>()

/**
 * The row you clicked, not the search you ran. A single click used to execute
 * the search and jump to another page, which made the list impossible to read
 * through: every attempt to look at an entry left the page.
 */
const selected = ref<StoredSearch | null>(null)
const error = ref('')

onMounted(() => history.load())

function describe(entry: StoredSearch): string {
  return describeQuery(t, entry.query)
}

function isSelected(entry: StoredSearch): boolean {
  return selected.value?.kind === entry.kind && selected.value?.id === entry.id
}

function select(entry: StoredSearch) {
  selected.value = isSelected(entry) ? null : entry
}

function run(entry: StoredSearch) {
  selected.value = entry
  history.markRun(entry)
  emit('run', entry)
}

/**
 * Keeping a recent means saving a copy: recents are trimmed and deduped.
 * @param entry
 */
async function keep(entry: StoredSearch) {
  await guard(() => saveSearch(entry.query, entry.query.term || t('Saved search')))
}

async function startRename(entry: StoredSearch) {
  const name = window.prompt(t('Rename this search'), entry.name ?? '')
  if (name === null) {
    return
  }
  await guard(() => history.rename(entry, name, entry.description ?? ''))
}

async function remove(entry: StoredSearch) {
  const confirmed = await showConfirmation({
    name: t('Delete search'),
    text: t('Delete "{name}"? This cannot be undone.', {
      name: entry.name || entry.query.term || t('this search'),
    }),
    labelConfirm: t('Delete'),
    labelReject: t('Cancel'),
    severity: 'warning',
  })
  if (!confirmed) {
    return
  }
  if (isSelected(entry)) {
    selected.value = null
  }
  await guard(() => history.remove(entry))
}

async function clearRecents() {
  const confirmed = await showConfirmation({
    name: t('Clear recent searches'),
    text: t('Clear all recent searches? This cannot be undone.'),
    labelConfirm: t('Clear'),
    labelReject: t('Cancel'),
    severity: 'warning',
  })
  if (!confirmed) {
    return
  }
  await guard(() => history.clearRecents())
}

/**
 * One place to turn a failed store action into a message; every one of them
 * fails the same way and for the same reasons.
 * @param action
 */
async function guard(action: () => Promise<unknown>) {
  error.value = ''
  try {
    await action()
  } catch (e) {
    error.value = (e as Error).message
  }
}
</script>

<style scoped lang="scss">
// The page fills the content area and starts at its left edge, like the other
// pages do — it used to be a centred 900px column, which read as a stray
// dialog rather than as one of the app's views.
.history {
  display: flex;
  flex-direction: column;
  height: 100%;
  min-height: 0;
  padding: 0 16px 12px;

  h3 {
    margin: 8px 0 4px;
    font-weight: 700;
  }

  h4 {
    margin: 0;
    font-weight: 700;
  }

  &__header {
    margin-bottom: 16px;

    p {
      margin: 0;
    }
  }

  &__loading {
    margin: 32px auto;
  }

  // Two equal columns, each free to be narrow: minmax(0, 1fr) rather than 1fr,
  // or a long unbreakable filename in one list widens that column and squeezes
  // the other. Below the breakpoint they stack, Saved first.
  &__columns {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 8px 24px;
    flex: 1 1 auto;
    min-height: 0;
  }

  &__column {
    display: flex;
    flex-direction: column;
    min-height: 0;
    min-width: 0;
  }

  &__heading {
    display: flex;
    align-items: center;
    gap: 6px;
    min-height: 40px;
    padding-bottom: 4px;
    border-bottom: 1px solid var(--color-border);
  }

  // Pushed to the far end of its heading so it never sits against the count.
  &__clear {
    margin-inline-start: auto;
  }

  // Only the lists scroll; the two headings stay level with each other.
  &__body {
    flex: 1 1 auto;
    min-height: 0;
    overflow-y: auto;
  }

  &__list {
    list-style: none;
    padding: 0;
    margin: 0;
  }

  &__item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 0 4px;
    border-bottom: 1px solid var(--color-border);
    border-radius: var(--border-radius);
    transition: background-color 0.1s ease;

    &:hover {
      background: var(--color-background-hover);
    }

    &--selected,
    &--selected:hover {
      background: var(--color-primary-element-light);
    }
  }

  &__run {
    display: flex;
    flex-direction: column;
    align-items: start;
    justify-content: center;
    gap: 2px;
    flex: 1 1 auto;
    min-width: 0;
    // Nextcloud core's server.css themes every plain <button> (background +
    // border-radius, even at rest) since this is meant to be a bare list row,
    // not a button — beat it explicitly rather than fight source order.
    background-color: transparent !important;
    border: none;
    border-radius: 0 !important;
    font: inherit;
    color: inherit;
    text-align: start;
    padding: 10px 4px;
    cursor: pointer;

    strong,
    span {
      max-width: 100%;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    &:focus-visible {
      outline: 2px solid var(--color-primary-element);
      outline-offset: -2px;
      border-radius: var(--border-radius);
    }
  }

  // Side by side needs room for two lists; narrower than this and neither
  // column is wide enough to read a query description in. Stacked, the pair
  // scrolls as one instead of each list keeping its own scrollbar.
  @media (max-width: 900px) {
    &__columns {
      grid-template-columns: minmax(0, 1fr);
      gap: 24px;
      overflow-y: auto;
      // Rows take the height they need: stretched, a short Saved list left a
      // field of white space above Recent.
      align-content: start;
    }

    &__body {
      overflow-y: visible;
    }
  }
}

.muted {
  color: var(--color-text-maxcontrast);
  font-size: 90%;
}
</style>
