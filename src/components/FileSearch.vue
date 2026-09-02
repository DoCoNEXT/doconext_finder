<template>
  <div class="finder">
    <!-- Search + the two presets people reach for, on one line. -->
    <div class="finder__bar">
      <NcTextField v-model="term"
                   class="finder__term"
                   :label="t('Search files')"
                   :label-outside="true"
                   :placeholder="t('Search by name…')"
                   @keydown.enter="run(0)">
        <template #icon>
          <NcIconSvgWrapper :path="mdiMagnify" :size="20" />
        </template>
      </NcTextField>

      <NcSelect v-model="fileType"
                class="finder__preset"
                label="label"
                :options="typeOptions"
                :clearable="false"
                :input-label="t('Type')" />

      <NcSelect v-model="modified"
                class="finder__preset"
                label="label"
                :options="timeOptions"
                :clearable="false"
                :input-label="t('Modified')" />

      <NcButton variant="primary" :disabled="loading" @click="run(0)">
        {{ t('Search') }}
      </NcButton>
    </div>

    <details class="finder__filters" :open="conditions.length > 0">
      <summary>{{ filterSummary }}</summary>

      <div class="finder__match">
        <span>{{ t('Match:') }}</span>
        <NcCheckboxRadioSwitch v-model="matchMode" type="radio" value="all" name="match">
          {{ t('all conditions') }}
        </NcCheckboxRadioSwitch>
        <NcCheckboxRadioSwitch v-model="matchMode" type="radio" value="any" name="match">
          {{ t('any condition') }}
        </NcCheckboxRadioSwitch>
      </div>

      <template v-if="schema">
        <ConditionRow v-for="(condition, index) in conditions"
                      :key="index"
                      :condition="condition"
                      :schema="schema"
                      @update:condition="conditions.splice(index, 1, $event)"
                      @remove="conditions.splice(index, 1)" />

        <NcButton @click="addCondition">
          {{ t('Add condition') }}
        </NcButton>
      </template>
      <NcLoadingIcon v-else :size="20" />
    </details>

    <NcNoteCard v-if="error" type="error">{{ error }}</NcNoteCard>

    <NcLoadingIcon v-if="loading" class="finder__loading" :size="32" />

    <NcEmptyContent v-else-if="searched && results.length === 0"
                    :name="t('No files found')"
                    :description="t('Try a different term, or loosen the filters.')">
      <template #icon>
        <NcIconSvgWrapper :path="mdiMagnify" />
      </template>
    </NcEmptyContent>

    <table v-else-if="results.length" class="results">
      <thead>
        <tr>
          <th class="results__star" />
          <th><SortHeader field="name" :sort="sort" :descending="descending" @sort="sortBy">{{ t('Name') }}</SortHeader></th>
          <th>{{ t('Folder') }}</th>
          <th class="numeric"><SortHeader field="size" :sort="sort" :descending="descending" @sort="sortBy">{{ t('Size') }}</SortHeader></th>
          <th><SortHeader field="mtime" :sort="sort" :descending="descending" @sort="sortBy">{{ t('Modified') }}</SortHeader></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="file in results" :key="file.fileid">
          <td class="results__star">
            <NcButton :aria-label="file.favorite ? t('Remove from favorites') : t('Add to favorites')"
                      variant="tertiary"
                      @click="toggleFavorite(file)">
              <template #icon>
                <NcIconSvgWrapper :path="file.favorite ? mdiStar : mdiStarOutline"
                                  :size="20"
                                  :class="{ 'results__star--on': file.favorite }" />
              </template>
            </NcButton>
          </td>
          <td>
            <a class="results__name" :href="fileLink(file)" target="_blank" rel="noreferrer noopener">
              <NcIconSvgWrapper :path="iconFor(file.mimetype, file.isFolder)" :size="20" />
              <span>{{ file.name }}</span>
            </a>
          </td>
          <td class="muted">{{ folderOf(file) }}</td>
          <td class="numeric">{{ file.isFolder ? '—' : formatSize(file.size) }}</td>
          <td class="muted">{{ formatDate(file.mtime) }}</td>
        </tr>
      </tbody>
    </table>

    <div v-if="results.length" class="finder__paging">
      <NcButton :disabled="offset === 0 || loading" @click="run(offset - pageSize)">
        {{ t('Previous') }}
      </NcButton>
      <NcButton :disabled="!hasMore || loading" @click="run(offset + pageSize)">
        {{ t('Next') }}
      </NcButton>
      <span class="muted">{{ rangeLabel }}</span>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import {
  NcButton,
  NcCheckboxRadioSwitch,
  NcEmptyContent,
  NcIconSvgWrapper,
  NcLoadingIcon,
  NcNoteCard,
  NcSelect,
  NcTextField,
} from '@nextcloud/vue'
import { mdiMagnify, mdiStar, mdiStarOutline } from '@mdi/js'
import { generateUrl } from '@nextcloud/router'
import { useI18n } from '../composables/useI18n'
import { SearchApi } from '../services/SearchApi'
import {
  anyTime,
  anyType,
  fileTypePresets,
  modifiedAfter,
  modifiedPresets,
} from '../filters/presets'
import type { FileTypePreset, ModifiedPreset } from '../filters/presets'
import { iconFor } from '../filters/fields'
import ConditionRow from './ConditionRow.vue'
import SortHeader from './SortHeader.vue'
import type { Condition, FieldsResponse, FileResult } from '../types/Search'

const { t } = useI18n()

const pageSize = 50

const term = ref('')
const typeOptions = fileTypePresets(t)
const timeOptions = modifiedPresets(t)
const fileType = ref<FileTypePreset>(anyType(t))
const modified = ref<ModifiedPreset>(anyTime(t))
const conditions = ref<Condition[]>([])
const matchMode = ref<'all' | 'any'>('all')
const sort = ref('mtime')
const descending = ref(true)

const schema = ref<FieldsResponse>()
const results = ref<FileResult[]>([])
const hasMore = ref(false)
const offset = ref(0)
const loading = ref(false)
const searched = ref(false)
const error = ref('')

onMounted(async () => {
  try {
    schema.value = await SearchApi.fields()
  } catch (e) {
    error.value = (e as Error).message
  }
})

const filterSummary = computed(() =>
  conditions.value.length
    ? t('Advanced filters ({count})', { count: conditions.value.length })
    : t('Advanced filters'))

const rangeLabel = computed(() => {
  const first = offset.value + 1
  const last = offset.value + results.value.length
  return hasMore.value
    ? t('Showing {first}–{last}+', { first, last })
    : t('Showing {first}–{last}', { first, last })
})

function addCondition() {
  const loaded = schema.value
  const field = loaded && Object.keys(loaded.fields)[0]
  const operator = field ? loaded.operators[field]?.[0] : undefined
  if (!field || !operator) {
    return
  }
  conditions.value.push({ field, operator, value: '' })
}

/**
 * Clicking a sortable header re-runs from page 1 — sorting is server-side.
 * @param field
 */
function sortBy(field: string) {
  if (sort.value === field) {
    descending.value = !descending.value
  } else {
    sort.value = field
    descending.value = true
  }
  run(0)
}

async function run(nextOffset: number) {
  const hasFilter = term.value.trim()
    || conditions.value.length > 0
    || fileType.value.mimetypes.length > 0
    || modified.value.seconds !== null

  if (!hasFilter) {
    error.value = t('Enter a search term, or pick a filter.')
    return
  }

  loading.value = true
  error.value = ''
  try {
    const response = await SearchApi.search({
      term: term.value.trim(),
      conditions: conditions.value.filter(usable),
      mimetypes: fileType.value.mimetypes,
      modifiedAfter: modifiedAfter(modified.value) ?? undefined,
      matchAny: matchMode.value === 'any',
      sort: sort.value,
      descending: descending.value,
      limit: pageSize,
      offset: Math.max(0, nextOffset),
    })
    results.value = response.results
    hasMore.value = response.hasMore
    offset.value = response.offset
    searched.value = true
  } catch (e) {
    error.value = (e as Error).message
    results.value = []
    hasMore.value = false
  } finally {
    loading.value = false
  }
}

/**
 * A half-filled row would fail the whole request, so skip rows with no value
 * rather than making the user delete them before searching.
 * @param condition
 */
function usable(condition: Condition): boolean {
  return condition.value !== '' && condition.value !== null && condition.value !== undefined
}

async function toggleFavorite(file: FileResult) {
  const next = !file.favorite
  file.favorite = next // optimistic: the star should not lag the click
  try {
    await SearchApi.setFavorite(file.fileid, next)
  } catch (e) {
    file.favorite = !next
    error.value = (e as Error).message
  }
}

function folderOf(file: FileResult): string {
  const at = file.path.lastIndexOf('/')
  return at === -1 ? '/' : file.path.slice(0, at)
}

function fileLink(file: FileResult): string {
  return generateUrl(`/f/${file.fileid}`)
}

function formatSize(bytes: number): string {
  if (bytes < 1024) {
    return `${bytes} B`
  }
  const units = ['KB', 'MB', 'GB', 'TB']
  let value = bytes / 1024
  let unit = 0
  while (value >= 1024 && unit < units.length - 1) {
    value /= 1024
    unit++
  }
  return `${value.toFixed(value < 10 ? 1 : 0)} ${units[unit]}`
}

function formatDate(unixSeconds: number): string {
  return new Date(unixSeconds * 1000).toLocaleString()
}
</script>

<style scoped lang="scss">
.finder {
  max-width: 1200px;
  margin: 0 auto;
  padding: 0 24px 24px;

  &__bar {
    display: flex;
    align-items: end;
    gap: 8px;
    margin-bottom: 16px;
    flex-wrap: wrap;
  }

  &__term {
    flex: 2 1 260px;
  }

  &__preset {
    flex: 1 1 170px;
    min-width: 170px;
  }

  &__filters {
    margin-bottom: 16px;

    summary {
      cursor: pointer;
      padding: 4px 0;
      color: var(--color-text-maxcontrast);
    }
  }

  &__match {
    display: flex;
    align-items: center;
    gap: 12px;
    margin: 8px 0 12px;
    color: var(--color-text-maxcontrast);
  }

  &__loading {
    margin: 32px auto;
  }

  &__paging {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 16px;
  }
}

.results {
  width: 100%;
  border-collapse: collapse;

  th,
  td {
    text-align: start;
    padding: 4px 8px;
    border-bottom: 1px solid var(--color-border);
  }

  th {
    color: var(--color-text-maxcontrast);
    font-weight: 600;
  }

  &__star {
    width: 44px;

    &--on {
      color: var(--color-favorite);
    }
  }

  &__name {
    display: flex;
    align-items: center;
    gap: 8px;

    &:hover span {
      text-decoration: underline;
    }
  }
}

.numeric {
  text-align: end;
  white-space: nowrap;
}

.muted {
  color: var(--color-text-maxcontrast);
}
</style>
