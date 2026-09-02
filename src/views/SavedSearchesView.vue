<template>
  <div class="history">
    <h3>{{ t('Saved searches') }}</h3>

    <NcNoteCard v-if="error" type="error">{{ error }}</NcNoteCard>
    <NcLoadingIcon v-if="loading" :size="28" class="history__loading" />

    <NcEmptyContent v-else-if="saved.length === 0"
                    :name="t('No saved searches yet')"
                    :description="t('Run a search, then use Save to keep it here.')">
      <template #icon>
        <NcIconSvgWrapper :path="mdiContentSaveOutline" />
      </template>
    </NcEmptyContent>

    <ul v-else class="history__list">
      <li v-for="entry in saved" :key="entry.id" class="history__item">
        <button class="history__run" @click="run(entry)">
          <strong>{{ entry.name }}</strong>
          <span class="muted">{{ describe(entry) }}</span>
          <span v-if="entry.description" class="muted">{{ entry.description }}</span>
        </button>
        <NcActions :aria-label="t('Actions')">
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
      <h3>{{ t('Recent searches') }}</h3>
      <NcButton v-if="recents.length" variant="tertiary" @click="clearRecents">
        {{ t('Clear') }}
      </NcButton>
    </div>

    <NcEmptyContent v-if="!loading && recents.length === 0"
                    :name="t('No recent searches')"
                    :description="t('Searches you run are listed here.')">
      <template #icon>
        <NcIconSvgWrapper :path="mdiHistory" />
      </template>
    </NcEmptyContent>

    <ul v-else class="history__list">
      <li v-for="entry in recents" :key="entry.id" class="history__item">
        <button class="history__run" @click="run(entry)">
          <strong>{{ entry.query.term || t('(no search term)') }}</strong>
          <span class="muted">{{ describe(entry) }}</span>
        </button>
        <NcActions :aria-label="t('Actions')">
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
import { mdiContentSaveOutline, mdiDelete, mdiHistory, mdiPencilOutline } from '@mdi/js'
import { useI18n } from '../composables/useI18n'
import { SearchApi } from '../services/SearchApi'
import { describeQuery } from '../filters/describe'
import type { StoredSearch } from '../types/Search'

const { t } = useI18n()

const emit = defineEmits<{ (e: 'run', entry: StoredSearch): void }>()

const saved = ref<StoredSearch[]>([])
const recents = ref<StoredSearch[]>([])
const loading = ref(false)
const error = ref('')

onMounted(load)

async function load() {
  loading.value = true
  error.value = ''
  try {
    const history = await SearchApi.history()
    saved.value = history.saved
    recents.value = history.recents
  } catch (e) {
    error.value = (e as Error).message
  } finally {
    loading.value = false
  }
}

defineExpose({ load })

function describe(entry: StoredSearch): string {
  return describeQuery(t, entry.query)
}

function run(entry: StoredSearch) {
  if (entry.kind === 'saved') {
    SearchApi.markRun(entry.id)
  }
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
  try {
    await SearchApi.save(name, '', entry.query)
    await load()
  } catch (e) {
    error.value = (e as Error).message
  }
}

async function startRename(entry: StoredSearch) {
  const name = window.prompt(t('Rename this search'), entry.name ?? '')
  if (name === null) {
    return
  }
  try {
    await SearchApi.rename(entry.id, name, entry.description ?? '')
    await load()
  } catch (e) {
    error.value = (e as Error).message
  }
}

async function remove(entry: StoredSearch) {
  try {
    await SearchApi.remove(entry.id)
    await load()
  } catch (e) {
    error.value = (e as Error).message
  }
}

async function clearRecents() {
  try {
    await SearchApi.clearRecents()
    await load()
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
    margin: 16px 0 8px;
    font-weight: 700;
  }

  &__heading {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 24px;
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
