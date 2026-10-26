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
        <NcButton variant="primary" :href="showInFilesLink(file)" target="_blank">
          <template #icon>
            <ExternalLink :size="16" />
          </template>
          {{ t('Show in Files') }}
        </NcButton>
        <NcButton v-if="canDownload(file)" variant="secondary" @click="downloadFile(file)">
          <template #icon>
            <Download :size="16" />
          </template>
          {{ t('Download') }}
        </NcButton>
      </div>
    </div>
  </NcModal>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { NcButton, NcEmptyContent, NcModal } from '@nextcloud/vue'
import { Download, ExternalLink, Eye } from '@lucide/vue'
import { generateUrl } from '@nextcloud/router'
import { useI18n } from '../composables/useI18n'
import { usePreviewStore } from '../stores/previewStore'
import { showInFilesLink } from '../composables/useFileCommands'
import { downloadFile } from '../services/download'
import { canDownload } from '../filters/access'
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
  // A fixed height, as DoCoNEXT Core's full preview has: the dialog does not
  // jump with the length of the document, and its buttons stay where the
  // platform's dialogs keep theirs, at the bottom on the right.
  display: flex;
  flex-direction: column;
  gap: 12px;
  height: min(80vh, 900px);
  padding: 12px 16px 16px;
  // Clear of the dialog's close button, which NcModal lays over the top right
  // of its content: a document as wide as the dialog ran under it.
  padding-block-start: calc(var(--default-clickable-area) + 12px);

  &__body {
    // The dialog is the frame; let the renderer use all of it.
    flex: 1;
    min-height: 0;
  }

  &__image {
    flex: 1;
    min-height: 0;
    max-width: 100%;
    object-fit: contain;
    align-self: center;
    border-radius: var(--border-radius-large);
    background: var(--color-background-dark);
  }

  &__actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: 8px;
    margin-top: auto;
  }
}
</style>
