<template>
  <div class="favorites">
    <div class="favorites__heading">
      <h3>{{ t('Favorites') }}</h3>
      <NcButton variant="tertiary" :disabled="loading" @click="load">
        {{ t('Refresh') }}
      </NcButton>
    </div>

    <NcNoteCard v-if="error" type="error">{{ error }}</NcNoteCard>
    <NcLoadingIcon v-if="loading" :size="32" class="favorites__loading" />

    <NcEmptyContent v-else-if="loaded && files.length === 0"
                    :name="t('No favorites yet')"
                    :description="t('Star a file in the search results to keep it here.')">
      <template #icon>
        <NcIconSvgWrapper :path="mdiStarOutline" />
      </template>
    </NcEmptyContent>

    <template v-else-if="files.length">
      <ViewOptions />
      <div class="favorites__results">
        <FileTable :files="files"
                   :sort="sort"
                   :descending="descending"
                   :selected-id="selection.file?.fileid"
                   @sort="sortBy"
                   @toggle-favorite="unfavorite"
                   @select="selection.select($event, preferences.sidebarPinned)" />
      </div>
    </template>

  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import {
  NcButton,
  NcEmptyContent,
  NcIconSvgWrapper,
  NcLoadingIcon,
  NcNoteCard,
} from '@nextcloud/vue'
import { mdiStarOutline } from '@mdi/js'
import { useI18n } from '../composables/useI18n'
import { SearchApi } from '../services/SearchApi'
import { useSelectionStore } from '../stores/selectionStore'
import { usePreferencesStore } from '../stores/preferencesStore'
import FileTable from '../components/FileTable.vue'
import ViewOptions from '../components/ViewOptions.vue'
import type { FileResult } from '../types/Search'

const { t } = useI18n()
const selection = useSelectionStore()
const preferences = usePreferencesStore()

const files = ref<FileResult[]>([])
const sort = ref('mtime')
const descending = ref(true)
const loading = ref(false)
const loaded = ref(false)
const error = ref('')

onMounted(load)

async function load() {
  loading.value = true
  error.value = ''
  try {
    // Favorites are just a search: the backend's `favorite` field is a presence
    // check, so one condition is the whole query.
    const response = await SearchApi.search({
      conditions: [{ field: 'favorite', operator: 'eq', value: true }],
      sort: sort.value,
      descending: descending.value,
      limit: 500,
    })
    files.value = response.results
    loaded.value = true
  } catch (e) {
    error.value = (e as Error).message
    files.value = []
  } finally {
    loading.value = false
  }
}

function sortBy(field: string) {
  if (sort.value === field) {
    descending.value = !descending.value
  } else {
    sort.value = field
    descending.value = true
  }
  load()
}

/**
 * Unstarring here removes the row: this list *is* the favorites, so leaving a
 * hollow star behind would show something that no longer belongs.
 * @param file
 */
async function unfavorite(file: FileResult) {
  try {
    await SearchApi.setFavorite(file.fileid, false)
    files.value = files.value.filter((f) => f.fileid !== file.fileid)
  } catch (e) {
    error.value = (e as Error).message
  }
}
</script>

<style scoped lang="scss">
.favorites {
  display: flex;
  flex-direction: column;
  height: 100%;
  min-height: 0;
  padding: 0 16px 12px;

  &__heading {
    display: flex;
    align-items: center;
    gap: 8px;

    h3 {
      margin: 16px 0 8px;
      font-weight: 700;
    }
  }

  &__loading {
    margin: 32px auto;
  }

  // Only the grid scrolls; the heading and view options stay put.
  &__results {
    flex: 1 1 auto;
    min-height: 0;
    overflow-y: auto;
  }
}
</style>
