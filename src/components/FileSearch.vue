<template>
  <div class="finder">
    <div class="finder__bar">
      <NcTextField v-model="term"
                   class="finder__term"
                   :label="t('Search files')"
                   :label-outside="true"
                   :placeholder="t('Search files…')"
                   @keydown.enter="run(0)" />
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
                      @update:condition="replaceCondition(index, $event)"
                      @update:negate="setNegate(index, $event)"
                      @remove="conditions.splice(index, 1)" />

        <NcButton @click="addCondition">
          {{ t('+ Add condition') }}
        </NcButton>
      </template>
      <NcLoadingIcon v-else :size="20" />
    </details>

    <NcNoteCard v-if="error" type="error">{{ error }}</NcNoteCard>

    <NcLoadingIcon v-if="loading" class="finder__loading" :size="32" />

    <NcEmptyContent v-else-if="searched && results.length === 0"
                    :name="t('No files found')"
                    :description="t('Try a different term, or loosen the conditions.')" />

    <table v-else-if="results.length" class="finder__results">
      <thead>
        <tr>
          <th>{{ t('Name') }}</th>
          <th>{{ t('Folder') }}</th>
          <th class="numeric">{{ t('Size') }}</th>
          <th>{{ t('Modified') }}</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="file in results" :key="file.fileid">
          <td>
            <a :href="fileLink(file)" target="_blank" rel="noreferrer noopener">
              {{ file.isFolder ? '📁' : '📄' }} {{ file.name }}
            </a>
          </td>
          <td class="muted">{{ folderOf(file) }}</td>
          <td class="numeric">{{ formatSize(file.size) }}</td>
          <td class="muted">{{ formatDate(file.mtime) }}</td>
        </tr>
      </tbody>
    </table>

    <div v-if="results.length" class="finder__paging">
      <NcButton :disabled="offset === 0 || loading" @click="run(offset - pageSize)">
        {{ t('‹ Previous') }}
      </NcButton>
      <NcButton :disabled="!hasMore || loading" @click="run(offset + pageSize)">
        {{ t('Next ›') }}
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
  NcLoadingIcon,
  NcNoteCard,
  NcTextField,
} from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import { useI18n } from '../composables/useI18n'
import { SearchApi } from '../services/SearchApi'
import ConditionRow from './ConditionRow.vue'
import type { Condition, FieldsResponse, FileResult } from '../types/Search'

const { t } = useI18n()

const pageSize = 50

const term = ref('')
const conditions = ref<Condition[]>([])
const matchMode = ref<'all' | 'any'>('all')
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
    ? t('Filters ({count})', { count: conditions.value.length })
    : t('Filters'))

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

function replaceCondition(index: number, condition: Condition) {
  conditions.value.splice(index, 1, condition)
}

function setNegate(index: number, negate: boolean) {
  const current = conditions.value[index]
  if (current) {
    conditions.value.splice(index, 1, { ...current, negate })
  }
}

async function run(nextOffset: number) {
  if (!term.value.trim() && conditions.value.length === 0) {
    error.value = t('Enter a search term, or add a condition.')
    return
  }

  loading.value = true
  error.value = ''
  try {
    const response = await SearchApi.search({
      term: term.value.trim(),
      conditions: conditions.value.map(normalise),
      matchAny: matchMode.value === 'any',
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
 * The value box is always text; coerce to what the field's type expects.
 * @param condition
 */
function normalise(condition: Condition): Condition {
  const kind = schema.value?.fields[condition.field]
  if (kind === 'integer') {
    return { ...condition, value: Number(condition.value) || 0 }
  }
  if (kind === 'boolean') {
    return { ...condition, value: true }
  }
  return condition
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
	max-width: 1100px;
	margin: 0 auto;
	padding: 24px;

	&__bar {
		display: flex;
		align-items: end;
		gap: 8px;
		margin-bottom: 16px;
	}

	&__term {
		flex: 1 1 auto;
	}

	&__filters {
		margin-bottom: 16px;

		summary {
			cursor: pointer;
			padding: 4px 0;
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

	&__results {
		width: 100%;
		border-collapse: collapse;

		th,
		td {
			text-align: start;
			padding: 6px 8px;
			border-bottom: 1px solid var(--color-border);
		}

		th {
			color: var(--color-text-maxcontrast);
			font-weight: 600;
		}

		.numeric {
			text-align: end;
			white-space: nowrap;
		}

		.muted {
			color: var(--color-text-maxcontrast);
		}
	}

	&__paging {
		display: flex;
		align-items: center;
		gap: 8px;
		margin-top: 16px;
	}
}
</style>
