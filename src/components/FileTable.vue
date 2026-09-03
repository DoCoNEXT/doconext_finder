<template>
  <!-- Scrolls on its own so opening the detail panel narrows the grid
       instead of clipping its right-hand columns. -->
  <div class="results-scroll">
    <table class="results">
      <thead>
        <tr>
          <th class="results__star" />
          <th v-for="column in columns" :key="column.id" :class="cellClass(column.id)">
            <SortHeader v-if="sortableAs(column.id)"
                        :field="sortableAs(column.id)!"
                        :sort="sort"
                        :descending="descending"
                        @sort="$emit('sort', $event)">
              {{ headerOf(column) }}
            </SortHeader>
            <template v-else>{{ headerOf(column) }}</template>
          </th>
          <th class="results__actions" />
        </tr>
      </thead>

      <tbody>
        <template v-for="row in visibleRows" :key="row.id">
          <tr v-if="row.kind === 'group'" class="results__group">
            <th :colspan="columns.length + 2"
                :class="`results__group--l${row.level}`"
                :style="{ paddingInlineStart: `${8 + row.level * 20}px` }">
              <button class="results__toggle" @click="toggle(row.id)">
                <NcIconSvgWrapper :path="collapsed.has(row.id) ? mdiChevronRight : mdiChevronDown"
                                  :size="18" />
                <span>{{ row.label }}</span>
                <span class="muted">({{ row.count }})</span>
              </button>
            </th>
          </tr>

          <tr v-else
              :class="{ 'results__row--selected': row.file.fileid === selectedId }"
              @click="$emit('select', row.file)"
              @dblclick="activate(row.file)">
            <td class="results__star">
              <NcButton :aria-label="row.file.favorite ? t('Remove from favorites') : t('Add to favorites')"
                        variant="tertiary"
                        @click.stop="$emit('toggle-favorite', row.file)">
                <template #icon>
                  <NcIconSvgWrapper :path="row.file.favorite ? mdiStar : mdiStarOutline"
                                    :size="20"
                                    :class="{ 'results__star--on': row.file.favorite }" />
                </template>
              </NcButton>
            </td>

            <td v-for="column in columns" :key="column.id" :class="cellClass(column.id)">
              <span v-if="column.id === 'name'" class="results__name" :title="row.file.name">
                <NcIconSvgWrapper :path="iconFor(row.file.mimetype, row.file.isFolder)" :size="20" />
                <span>{{ row.file.name }}</span>
              </span>
              <span v-else :title="cellText(row.file, column.id)" class="muted">
                {{ cellText(row.file, column.id) }}
              </span>
            </td>

            <td class="results__actions">
              <FileCommands :file="row.file"
                            @toggle-favorite="$emit('toggle-favorite', $event)"
                            @changed="$emit('changed', $event)" />
            </td>
          </tr>
        </template>
      </tbody>
    </table>
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { NcButton, NcIconSvgWrapper } from '@nextcloud/vue'
import {
  mdiChevronDown,
  mdiChevronRight,
  mdiStar,
  mdiStarOutline,
} from '@mdi/js'
import { useI18n } from '../composables/useI18n'
import { usePreferencesStore } from '../stores/preferencesStore'
import { useSearchStore } from '../stores/searchStore'
import { useFileCommands } from '../composables/useFileCommands'
import { iconFor } from '../filters/fields'
import { columnLabel, columnSortField, columnValue, isNumericColumn } from '../filters/columns'
import { buildRows } from '../filters/grouping'
import FileCommands from './FileCommands.vue'
import SortHeader from './SortHeader.vue'
import type { ColumnPref, FileResult, GroupScope } from '../types/Search'

const { t } = useI18n()
const preferences = usePreferencesStore()
const search = useSearchStore()
const { fileLink, folderLink } = useFileCommands()

const props = defineProps<{
  files: FileResult[]
  sort: string
  descending: boolean
  selectedId?: number
  /** Which list this is; grouping levels are kept per list. */
  scope: GroupScope
}>()

defineEmits<{
  (e: 'sort', field: string): void
  (e: 'toggle-favorite', file: FileResult): void
  (e: 'select', file: FileResult): void
  (e: 'changed', file: FileResult): void
}>()

const metadata = computed(() => search.schema?.metadata ?? [])

const columns = computed(() => preferences.visibleColumns)

const allRows = computed(() =>
  buildRows(t, props.files, preferences.groupingFor(props.scope)))

/**
 * Collapsed groups are held by id, and the ids carry the group path — so a group
 * stays collapsed while the rows around it change, and is forgotten once its
 * grouping level is removed.
 */
const collapsed = ref(new Set<string>())

function toggle(id: string) {
  const next = new Set(collapsed.value)
  if (!next.delete(id)) {
    next.add(id)
  }
  collapsed.value = next
}

/**
 * Hides everything under a collapsed header, nested headers included. It walks
 * the list rather than filtering row by row because a row belongs to a collapsed
 * group exactly when its id starts with that group's id.
 */
const visibleRows = computed(() => {
  if (collapsed.value.size === 0) {
    return allRows.value
  }

  const hidden: string[] = []
  return allRows.value.filter((row) => {
    while (hidden.length > 0 && !row.id.startsWith(`${hidden[hidden.length - 1]}/`)) {
      hidden.pop()
    }
    if (hidden.length > 0) {
      return false
    }
    if (row.kind === 'group' && collapsed.value.has(row.id)) {
      hidden.push(row.id)
      return true // the header stays, or there would be no way to reopen it
    }
    return true
  })
})

/**
 * What a double-click does is the user's choice.
 * @param file
 */
function activate(file: FileResult) {
  switch (preferences.doubleClick) {
    case 'folder':
      window.open(folderLink(file), '_blank', 'noreferrer')
      break
    case 'none':
      break
    default:
      window.open(fileLink(file), '_blank', 'noreferrer')
  }
}

/**
 * Built-in header, unless the user renamed the column.
 * @param column
 */
function headerOf(column: ColumnPref): string {
  return column.label || columnLabel(t, column.id, metadata.value)
}

function sortableAs(id: string): string | null {
  return columnSortField(id, metadata.value)
}

function cellClass(id: string): string {
  return isNumericColumn(id) ? 'numeric' : ''
}

function cellText(file: FileResult, id: string): string {
  return columnValue(t, file, id)
}
</script>

<style scoped lang="scss">
.results-scroll {
  overflow-x: auto;
}

.results {
  width: 100%;
  min-width: 640px;
  border-collapse: collapse;

  th,
  td {
    text-align: start;
    padding: 4px 8px;
    border-bottom: 1px solid var(--color-border);
    max-width: 340px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  th {
    color: var(--color-text-maxcontrast);
    font-weight: 600;
  }

  tbody tr {
    cursor: pointer;

    &:hover {
      background: var(--color-background-hover);
    }
  }

  &__group th {
    padding-top: 14px;
    color: var(--color-main-text);
    font-weight: 700;
    background: var(--color-background-soft, transparent);
  }

  // Deeper levels read as subordinate rather than as more headings.
  &__group--l1 th,
  &__group--l1 {
    font-weight: 600;
    font-size: 95%;
  }

  &__toggle {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: none;
    border: none;
    padding: 0;
    font: inherit;
    color: inherit;
    cursor: pointer;
  }

  &__group--l2,
  &__group--l3 {
    font-weight: 600;
    font-size: 90%;
    color: var(--color-text-maxcontrast);
  }

  &__row--selected {
    background: var(--color-primary-element-light);
  }

  &__star {
    width: 44px;

    &--on {
      color: var(--color-favorite);
    }
  }

  &__actions {
    width: 44px;
  }

  &__name {
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: 0;

    svg {
      flex: 0 0 auto;
    }

    span {
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

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
