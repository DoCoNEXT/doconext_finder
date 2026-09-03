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
              <NcActions :aria-label="t('Actions')" @click.stop>
                <NcActionLink :href="fileLink(row.file)" target="_blank">
                  <template #icon>
                    <NcIconSvgWrapper :path="mdiOpenInNew" :size="20" />
                  </template>
                  {{ t('Open in Files') }}
                </NcActionLink>
                <NcActionLink :href="folderLink(row.file)" target="_blank">
                  <template #icon>
                    <NcIconSvgWrapper :path="mdiFolderOpen" :size="20" />
                  </template>
                  {{ t('Open containing folder') }}
                </NcActionLink>
                <NcActionButton v-if="!row.file.isFolder" @click="downloadFile(row.file)">
                  <template #icon>
                    <NcIconSvgWrapper :path="mdiDownload" :size="20" />
                  </template>
                  {{ t('Download') }}
                </NcActionButton>
              </NcActions>
            </td>
          </tr>
        </template>
      </tbody>
    </table>
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { NcActionButton, NcActionLink, NcActions, NcButton, NcIconSvgWrapper } from '@nextcloud/vue'
import {
  mdiChevronDown,
  mdiChevronRight,
  mdiDownload,
  mdiFolderOpen,
  mdiOpenInNew,
  mdiStar,
  mdiStarOutline,
} from '@mdi/js'
import { generateUrl } from '@nextcloud/router'
import { downloadFile } from '../services/download'
import { useI18n } from '../composables/useI18n'
import { usePreferencesStore } from '../stores/preferencesStore'
import { useSearchStore } from '../stores/searchStore'
import { isMetadataField, metadataKeyOf } from '../filters/metadata'
import { iconFor } from '../filters/fields'
import { buildRows, folderOf, typeName } from '../filters/grouping'
import SortHeader from './SortHeader.vue'
import type { ColumnPref, FileResult } from '../types/Search'

const { t } = useI18n()
const preferences = usePreferencesStore()
const search = useSearchStore()

const metadataField = (id: string) => search.schema?.metadata.find((f) => f.field === id)

/**
 * Falls back to the bare key, so an uninstalled app leaves a heading not a blank.
 * @param id
 */
function metadataLabel(id: string): string {
  return metadataField(id)?.label ?? metadataKeyOf(id)
}

/**
 * Double-click opens the file where Nextcloud itself would.
 * @param file
 */
function open(file: FileResult) {
  window.open(fileLink(file), '_blank', 'noreferrer')
}

const props = defineProps<{
  files: FileResult[]
  sort: string
  descending: boolean
  selectedId?: number
}>()

defineEmits<{
  (e: 'sort', field: string): void
  (e: 'toggle-favorite', file: FileResult): void
  (e: 'select', file: FileResult): void
}>()

const columns = computed(() => preferences.visibleColumns)

const allRows = computed(() => buildRows(t, props.files, preferences.grouping))

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
      open(file)
  }
}

/**
 * Built-in header, unless the user renamed the column.
 * @param column
 */
function headerOf(column: ColumnPref): string {
  if (column.label) {
    return column.label
  }
  if (isMetadataField(column.id)) {
    return metadataLabel(column.id)
  }
  switch (column.id) {
    case 'name': return t('Name')
    case 'folder': return t('Folder')
    case 'size': return t('Size')
    case 'modified': return t('Modified')
    case 'created': return t('Created')
    case 'type': return t('Type')
    default: return column.id
  }
}

/**
 * Which backend sort field a column maps to, or null when it is not sortable.
 * @param id
 */
function sortableAs(id: string): string | null {
  if (isMetadataField(id)) {
    // Only indexed keys have anything to sort on.
    return metadataField(id)?.filterable ? id : null
  }
  switch (id) {
    case 'name': return 'name'
    case 'size': return 'size'
    case 'modified': return 'mtime'
    case 'created': return 'creation_time'
    default: return null
  }
}

function cellClass(id: string): string {
  return id === 'size' ? 'numeric' : ''
}

function cellText(file: FileResult, id: string): string {
  if (isMetadataField(id)) {
    return file.metadata?.[metadataKeyOf(id)] ?? ''
  }
  switch (id) {
    case 'folder': return folderOf(file)
    case 'size': return file.isFolder ? '—' : formatSize(file.size)
    case 'modified': return formatDate(file.mtime)
    case 'created': return file.creationTime > 0 ? formatDate(file.creationTime) : '—'
    case 'type': return typeName(t, file)
    default: return ''
  }
}

/**
 * /f/{id} is Nextcloud's own permalink; it resolves folders as well as files.
 * @param file
 */
function fileLink(file: FileResult): string {
  return generateUrl(`/f/${file.fileid}`)
}

function folderLink(file: FileResult): string {
  return `${generateUrl('/apps/files/files')}?dir=${encodeURIComponent('/' + folderOf(file))}`
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
