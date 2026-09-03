<template>
  <NcAppSidebar v-if="file"
                :name="file.name"
                :subname="folderOf(file) || '/'"
                @close="$emit('close')">
    <template #secondary-actions>
      <NcActionCheckbox :model-value="preferences.sidebarPinned"
                        @update:model-value="preferences.setSidebarPinned($event)">
        {{ t('Keep this panel open') }}
      </NcActionCheckbox>
    </template>
    <NcAppSidebarTab id="details" :name="t('Details')" :order="1">
      <template #icon>
        <NcIconSvgWrapper :path="mdiInformationOutline" :size="20" />
      </template>

      <!--
        Previews come from Nextcloud's own /core/preview endpoint. It only has
        one for types a provider handles, so the image is shown optimistically
        and removed if it fails, rather than probing first.
      -->
      <img v-if="previewUrl && !previewFailed"
           class="details__preview"
           :src="previewUrl"
           :alt="t('Preview of {name}', { name: file.name })"
           @error="previewFailed = true">

      <dl class="details">
        <dt>{{ t('Type') }}</dt>
        <dd>{{ typeName(t, file) }}</dd>

        <dt>{{ t('Size') }}</dt>
        <dd>{{ file.isFolder ? t('Folder') : formatSize(file.size) }}</dd>

        <dt>{{ t('Modified') }}</dt>
        <dd>{{ formatDate(file.mtime) }}</dd>

        <dt v-if="file.creationTime > 0">{{ t('Created') }}</dt>
        <dd v-if="file.creationTime > 0">{{ formatDate(file.creationTime) }}</dd>

        <dt>{{ t('Folder') }}</dt>
        <dd>{{ folderOf(file) || '/' }}</dd>

        <dt>{{ t('Media type') }}</dt>
        <dd class="details__mime">{{ file.mimetype }}</dd>
      </dl>

      <!--
        Everything the server holds for this file, whichever app put it there —
        Core's fields when Core is installed. Labels come from the same registry
        the columns use, so a field reads the same wherever it appears.
      -->
      <template v-if="metadata.length">
        <h4 class="details__heading">{{ t('Metadata') }}</h4>
        <dl class="details">
          <template v-for="entry in metadata" :key="entry.key">
            <dt>{{ entry.label }}</dt>
            <dd>{{ entry.value }}</dd>
          </template>
        </dl>
      </template>

      <div class="details__actions">
        <NcButton variant="primary" :href="fileLink(file)" target="_blank">
          {{ t('Open in Files') }}
        </NcButton>
        <NcButton v-if="!file.isFolder" :href="downloadLink(file)">
          {{ t('Download') }}
        </NcButton>
        <NcButton @click="$emit('toggle-favorite', file)">
          {{ file.favorite ? t('Remove from favorites') : t('Add to favorites') }}
        </NcButton>
      </div>
    </NcAppSidebarTab>
  </NcAppSidebar>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import {
  NcActionCheckbox,
  NcAppSidebar,
  NcAppSidebarTab,
  NcButton,
  NcIconSvgWrapper,
} from '@nextcloud/vue'
import { mdiInformationOutline } from '@mdi/js'
import { generateRemoteUrl, generateUrl } from '@nextcloud/router'
import { getCurrentUser } from '@nextcloud/auth'
import { useI18n } from '../composables/useI18n'
import { useSearchStore } from '../stores/searchStore'
import { usePreferencesStore } from '../stores/preferencesStore'
import { folderOf, typeName } from '../filters/grouping'
import type { FileResult } from '../types/Search'

const { t } = useI18n()
const search = useSearchStore()
const preferences = usePreferencesStore()

const props = defineProps<{ file: FileResult | null }>()

defineEmits<{
  (e: 'close'): void
  (e: 'toggle-favorite', file: FileResult): void
}>()

/**
 * Only the keys this file actually carries, labelled and sorted, so the panel
 * shows what is there rather than a hundred mostly-empty rows.
 */
const metadata = computed(() => {
  const values = props.file?.metadata ?? {}
  const known = search.schema?.metadata ?? []

  return Object.entries(values)
    .map(([key, value]) => ({
      key,
      value,
      label: known.find((f) => f.key === key)?.label ?? key,
    }))
    .sort((a, b) => a.label.localeCompare(b.label))
})

const previewFailed = ref(false)

// A new selection gets a fresh attempt: the previous file having no preview
// says nothing about this one.
watch(() => props.file?.fileid, () => { previewFailed.value = false })

const previewUrl = computed(() => {
  const file = props.file
  if (!file || file.isFolder) {
    return ''
  }
  return generateUrl(`/core/preview?fileId=${file.fileid}&x=512&y=512&a=1`)
})

function fileLink(file: FileResult): string {
  return generateUrl(`/f/${file.fileid}`)
}

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
.details {
  display: grid;
  grid-template-columns: auto 1fr;
  gap: 4px 12px;
  margin: 12px 0;

  dt {
    color: var(--color-text-maxcontrast);
  }

  dd {
    margin: 0;
    overflow-wrap: anywhere;
  }

  &__preview {
    display: block;
    width: 100%;
    max-height: 320px;
    object-fit: contain;
    border-radius: var(--border-radius-large);
    background: var(--color-background-dark);
  }

  &__heading {
    margin: 20px 0 4px;
    font-weight: 700;
  }

  &__mime {
    font-family: monospace;
    font-size: 90%;
  }

  &__actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 16px;
  }
}
</style>
