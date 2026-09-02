<template>
  <table class="results">
    <thead>
      <tr>
        <th class="results__star" />
        <th>
          <SortHeader field="name" :sort="sort" :descending="descending" @sort="$emit('sort', $event)">
            {{ t('Name') }}
          </SortHeader>
        </th>
        <th>{{ t('Folder') }}</th>
        <th class="numeric">
          <SortHeader field="size" :sort="sort" :descending="descending" @sort="$emit('sort', $event)">
            {{ t('Size') }}
          </SortHeader>
        </th>
        <th>
          <SortHeader field="mtime" :sort="sort" :descending="descending" @sort="$emit('sort', $event)">
            {{ t('Modified') }}
          </SortHeader>
        </th>
        <th class="results__actions" />
      </tr>
    </thead>
    <tbody>
      <tr v-for="file in files" :key="file.fileid">
        <td class="results__star">
          <NcButton :aria-label="file.favorite ? t('Remove from favorites') : t('Add to favorites')"
                    variant="tertiary"
                    @click="$emit('toggle-favorite', file)">
            <template #icon>
              <NcIconSvgWrapper :path="file.favorite ? mdiStar : mdiStarOutline"
                                :size="20"
                                :class="{ 'results__star--on': file.favorite }" />
            </template>
          </NcButton>
        </td>
        <td>
          <a class="results__name"
             :href="fileLink(file)"
             :title="file.name"
             target="_blank"
             rel="noreferrer noopener">
            <NcIconSvgWrapper :path="iconFor(file.mimetype, file.isFolder)" :size="20" />
            <span>{{ file.name }}</span>
          </a>
        </td>
        <td class="muted" :title="folderOf(file)">{{ folderOf(file) }}</td>
        <td class="numeric">{{ file.isFolder ? '—' : formatSize(file.size) }}</td>
        <td class="muted">{{ formatDate(file.mtime) }}</td>
        <td class="results__actions">
          <NcActions :aria-label="t('Actions')">
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
</template>

<script setup lang="ts">
import {
  NcActionLink,
  NcActions,
  NcButton,
  NcIconSvgWrapper,
} from '@nextcloud/vue'
import { mdiDownload, mdiFolderOpen, mdiOpenInNew, mdiStar, mdiStarOutline } from '@mdi/js'
import { generateUrl, generateRemoteUrl } from '@nextcloud/router'
import { getCurrentUser } from '@nextcloud/auth'
import { useI18n } from '../composables/useI18n'
import { iconFor } from '../filters/fields'
import SortHeader from './SortHeader.vue'
import type { FileResult } from '../types/Search'

const { t } = useI18n()

defineProps<{
  files: FileResult[]
  sort: string
  descending: boolean
}>()

defineEmits<{
  (e: 'sort', field: string): void
  (e: 'toggle-favorite', file: FileResult): void
}>()

function folderOf(file: FileResult): string {
  const at = file.path.lastIndexOf('/')
  return at === -1 ? '/' : file.path.slice(0, at)
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
 * already addressable there, and proxying bytes through a PHP endpoint would buy
 * nothing but memory pressure.
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
.results {
  width: 100%;
  border-collapse: collapse;

  th,
  td {
    text-align: start;
    padding: 4px 8px;
    border-bottom: 1px solid var(--color-border);
    max-width: 380px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
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

  &__actions {
    width: 44px;
  }

  &__name {
    display: flex;
    align-items: center;
    gap: 8px;
    // A flex item will not shrink below its content unless told to, so a long
    // file name pushed past the cell and ran into the next column instead of
    // ellipsising.
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
