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

      <tbody v-for="group in groups" :key="group.key">
        <tr v-if="group.label" class="results__group">
          <th :colspan="columns.length + 2">
            {{ group.label }} <span class="muted">({{ group.files.length }})</span>
          </th>
        </tr>
        <tr v-for="file in group.files"
            :key="file.fileid"
            :class="{ 'results__row--selected': file.fileid === selectedId }"
            @click="$emit('select', file)">
          <td class="results__star">
            <NcButton :aria-label="file.favorite ? t('Remove from favorites') : t('Add to favorites')"
                      variant="tertiary"
                      @click.stop="$emit('toggle-favorite', file)">
              <template #icon>
                <NcIconSvgWrapper :path="file.favorite ? mdiStar : mdiStarOutline"
                                  :size="20"
                                  :class="{ 'results__star--on': file.favorite }" />
              </template>
            </NcButton>
          </td>

          <td v-for="column in columns" :key="column.id" :class="cellClass(column.id)">
            <a v-if="column.id === 'name'"
               class="results__name"
               :href="fileLink(file)"
               :title="file.name"
               target="_blank"
               rel="noreferrer noopener"
               @click.stop>
              <NcIconSvgWrapper :path="iconFor(file.mimetype, file.isFolder)" :size="20" />
              <span>{{ file.name }}</span>
            </a>
            <span v-else :title="cellText(file, column.id)" class="muted">{{ cellText(file, column.id) }}</span>
          </td>

          <td class="results__actions">
            <NcActions :aria-label="t('Actions')" @click.stop>
              <NcActionLink :href="fileLink(file)" target="_blank">
                <template #icon>
                  <NcIconSvgWrapper :path="mdiOpenInNew" :size="20" />
                </template>
                {{ t('Open in Files') }}
              </NcActionLink>
              <NcActionLink :href="folderLink(file)" target="_blank">
                <template #icon>
                  <NcIconSvgWrapper :path="mdiFolderOpen" :size="20" />
                </template>
                {{ t('Open containing folder') }}
              </NcActionLink>
              <NcActionLink v-if="!file.isFolder" :href="downloadLink(file)">
                <template #icon>
                  <NcIconSvgWrapper :path="mdiDownload" :size="20" />
                </template>
                {{ t('Download') }}
              </NcActionLink>
            </NcActions>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { NcActionLink, NcActions, NcButton, NcIconSvgWrapper } from '@nextcloud/vue'
import { mdiDownload, mdiFolderOpen, mdiOpenInNew, mdiStar, mdiStarOutline } from '@mdi/js'
import { generateRemoteUrl, generateUrl } from '@nextcloud/router'
import { getCurrentUser } from '@nextcloud/auth'
import { useI18n } from '../composables/useI18n'
import { usePreferencesStore } from '../stores/preferencesStore'
import { iconFor } from '../filters/fields'
import { folderOf, groupFiles, typeName } from '../filters/grouping'
import SortHeader from './SortHeader.vue'
import type { ColumnPref, FileResult } from '../types/Search'

const { t } = useI18n()
const preferences = usePreferencesStore()

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

const groups = computed(() => groupFiles(t, props.files, preferences.grouping))

/**
 * Built-in header, unless the user renamed the column.
 * @param column
 */
function headerOf(column: ColumnPref): string {
  if (column.label) {
    return column.label
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

/**
 * Download goes straight at WebDAV rather than through the app: the file is
 * already addressable there, and proxying bytes through PHP would buy nothing
 * but memory pressure.
 * @param file
 */
function downloadLink(file: FileResult): string {
  const user = getCurrentUser()?.uid ?? ''
  return `${generateRemoteUrl('dav')}/files/${encodeURIComponent(user)}/${file.path
    .split('/')
    .map(encodeURIComponent)
    .join('/')}`
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
