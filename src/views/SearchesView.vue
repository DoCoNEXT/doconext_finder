<template>
  <div class="history">
    <h3>{{ t('Searches') }}</h3>
    <p class="muted">
      {{ t('Click to select, double-click or use Run search to execute one.') }}
    </p>

    <NcNoteCard v-if="error" type="error">{{ error }}</NcNoteCard>
    <NcLoadingIcon v-if="history.loading" :size="28" class="history__loading" />

    <h4>{{ t('Saved') }}</h4>

    <NcEmptyContent v-if="!history.loading && history.saved.length === 0"
                    :name="t('No saved searches yet')"
                    :description="t('Run a search, then use Save to keep it here.')">
      <template #icon>
        <NcIconSvgWrapper :path="mdiContentSaveOutline" />
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
              <NcIconSvgWrapper :path="mdiPlayOutline" :size="20" />
            </template>
            {{ t('Run search') }}
          </NcActionButton>
          <NcActionButton @click="startRename(entry)">
            <template #icon>
              <NcIconSvgWrapper :path="mdiPencilOutline" :size="20" />
            </template>
            {{ t('Rename') }}
          </NcActionButton>
          <NcActionButton @click="remove(entry)">
            <template #icon>
              <NcIconSvgWrapper :path="mdiDelete" :size="20" />
            </template>
            {{ t('Delete') }}
          </NcActionButton>
        </NcActions>
      </li>
    </ul>

    <div class="history__heading">
      <h4>{{ t('Recent') }}</h4>
      <NcButton v-if="history.recents.length" variant="tertiary" @click="clearRecents">
        {{ t('Clear') }}
      </NcButton>
    </div>

    <NcEmptyContent v-if="!history.loading && history.recents.length === 0"
                    :name="t('No recent searches')"
                    :description="t('Searches you run are listed here.')">
      <template #icon>
        <NcIconSvgWrapper :path="mdiHistory" />
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
              <NcIconSvgWrapper :path="mdiPlayOutline" :size="20" />
            </template>
            {{ t('Run search') }}
          </NcActionButton>
          <NcActionButton @click="keep(entry)">
            <template #icon>
              <NcIconSvgWrapper :path="mdiContentSaveOutline" :size="20" />
            </template>
            {{ t('Save this search') }}
          </NcActionButton>
          <NcActionButton @click="remove(entry)">
            <template #icon>
              <NcIconSvgWrapper :path="mdiDelete" :size="20" />
            </template>
            {{ t('Delete') }}
          </NcActionButton>
        </NcActions>
      </li>
    </ul>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import {
  NcActionButton,
  NcActions,
  NcButton,
  NcEmptyContent,
  NcIconSvgWrapper,
  NcLoadingIcon,
  NcNoteCard,
} from '@nextcloud/vue'
import {
  mdiContentSaveOutline,
  mdiDelete,
  mdiHistory,
  mdiPencilOutline,
  mdiPlayOutline,
} from '@mdi/js'
import { useI18n } from '../composables/useI18n'
import { useHistoryStore } from '../stores/historyStore'
import { describeQuery } from '../filters/describe'
import type { StoredSearch } from '../types/Search'

const { t } = useI18n()
const history = useHistoryStore()

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
  const name = window.prompt(t('Name this search'), entry.query.term || t('Saved search'))
  if (name === null) {
    return
  }
  await guard(() => history.save(name, '', entry.query))
}

async function startRename(entry: StoredSearch) {
  const name = window.prompt(t('Rename this search'), entry.name ?? '')
  if (name === null) {
    return
  }
  await guard(() => history.rename(entry, name, entry.description ?? ''))
}

async function remove(entry: StoredSearch) {
  if (isSelected(entry)) {
    selected.value = null
  }
  await guard(() => history.remove(entry))
}

async function clearRecents() {
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
.history {
  max-width: 900px;
  margin: 0 auto;
  padding: 0 24px 24px;

  h3 {
    margin: 8px 0 4px;
    font-weight: 700;
  }

  h4 {
    margin: 24px 0 8px;
    font-weight: 700;
  }

  &__heading {
    display: flex;
    align-items: center;
    gap: 8px;

    h4 {
      margin-bottom: 8px;
    }
  }

  &__loading {
    margin: 24px auto;
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
    border-bottom: 1px solid var(--color-border);
    border-radius: var(--border-radius);

    &--selected {
      background: var(--color-primary-element-light);
    }
  }

  &__run {
    display: flex;
    flex-direction: column;
    align-items: start;
    gap: 2px;
    flex: 1 1 auto;
    background: none;
    border: none;
    font: inherit;
    color: inherit;
    text-align: start;
    padding: 10px 4px;
    cursor: pointer;
    border-radius: var(--border-radius);

    &:hover {
      background: var(--color-background-hover);
    }
  }
}

.muted {
  color: var(--color-text-maxcontrast);
  font-size: 90%;
}
</style>
