<template>
  <NcModal v-if="file"
           size="large"
           :name="file.name"
           @close="preview.close()">
    <div class="full-preview">
      <!--
        Same two renderers as the details panel, at full width: the Files
        Preview app's element when that app is installed, Nextcloud's own
        thumbnail otherwise.
      -->
      <RichPreview v-if="richPreview"
                   :file="file"
                   :highlight="highlight"
                   class="full-preview__body" />

      <img v-else-if="previewUrl && !failed"
           class="full-preview__image"
           :src="previewUrl"
           :alt="t('Preview of {name}', { name: file.name })"
           @error="failed = true">

      <NcEmptyContent v-else
                      :name="t('No preview available')"
                      :description="t('This file type cannot be shown here.')">
        <template #icon>
          <Eye />
        </template>
      </NcEmptyContent>

      <div class="full-preview__actions">
        <NcButton variant="primary" :href="fileLink(file)" target="_blank">
          {{ t('Open in Files') }}
        </NcButton>
        <NcButton @click="downloadFile(file)">
          {{ t('Download') }}
        </NcButton>
      </div>
    </div>
  </NcModal>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { NcButton, NcEmptyContent, NcModal } from '@nextcloud/vue'
import { Eye } from '@lucide/vue'
import { generateUrl } from '@nextcloud/router'
import { useI18n } from '../composables/useI18n'
import { usePreviewStore } from '../stores/previewStore'
import { fileLink } from '../composables/useFileCommands'
import { downloadFile } from '../services/download'
import { HAS_RICH_PREVIEW } from '../constants'
import RichPreview from './RichPreview.vue'

const { t } = useI18n()
const preview = usePreviewStore()

defineProps<{
  /** The words the search looked for; the preview marks them in the document. */
  highlight?: string
}>()

const file = computed(() => preview.file)
const richPreview = HAS_RICH_PREVIEW
const failed = ref(false)

watch(file, () => { failed.value = false })

const previewUrl = computed(() => {
  const current = file.value
  return current && !current.isFolder
    ? generateUrl(`/core/preview?fileId=${current.fileid}&x=1600&y=1600&a=1`)
    : ''
})
</script>

<style scoped lang="scss">
.full-preview {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 16px;
  min-height: 50vh;

  &__body {
    // The dialog is the frame; let the renderer use all of it.
    max-height: 70vh;
  }

  &__image {
    max-width: 100%;
    max-height: 70vh;
    object-fit: contain;
    align-self: center;
    border-radius: var(--border-radius-large);
    background: var(--color-background-dark);
  }

  &__actions {
    display: flex;
    gap: 8px;
  }
}
</style>
