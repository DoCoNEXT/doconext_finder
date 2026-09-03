<template>
  <NcAppSidebar v-if="file"
                :name="file.name"
                :subname="folderOf(file) || '/'"
                :active="activeTab"
                :starred="file.favorite"
                @update:starred="$emit('toggle-favorite', file)"
                @close="$emit('close')"
                @update:active="activeTab = $event">
    <!--
      Every command lives in the header menu, the way Core and Finder for
      desktop do it: a row of buttons at the foot of the panel put the most
      useful actions where you had to scroll to reach them, and grew with every
      command added. The menu sizes itself and never pushes the details down.
    -->
    <template #secondary-actions>
      <NcActionLink v-for="command in linkCommands"
                    :key="command.id"
                    :href="command.href"
                    :target="command.target">
        <template #icon>
          <NcIconSvgWrapper :path="command.icon" :size="20" />
        </template>
        {{ command.label }}
      </NcActionLink>
      <NcActionButton v-for="command in buttonCommands"
                      :key="command.id"
                      @click="command.run?.()">
        <template #icon>
          <NcIconSvgWrapper :path="command.icon" :size="20" />
        </template>
        {{ command.label }}
      </NcActionButton>
      <NcActionSeparator />
      <NcActionCheckbox :model-value="preferences.sidebarPinned"
                        @update:model-value="preferences.setSidebarPinned($event)">
        {{ t('Keep this panel open') }}
      </NcActionCheckbox>
    </template>

    <NcAppSidebarTab id="details" :name="t('Details')" :order="1">
      <template #icon>
        <NcIconSvgWrapper :path="mdiInformationOutline" :size="20" />
      </template>

      <dl class="details">
        <dt>{{ t('Type') }}</dt>
        <dd>{{ typeName(t, file) }}</dd>

        <dt>{{ t('Media type') }}</dt>
        <dd class="details__mime">{{ file.isFolder ? t('Folder') : file.mimetype }}</dd>

        <dt>{{ t('Size') }}</dt>
        <dd>{{ file.isFolder ? t('Folder') : formatSize(file.size) }}</dd>

        <dt>{{ t('Modified') }}</dt>
        <dd>{{ formatDate(file.mtime) }}</dd>

        <template v-if="file.modifiedBy">
          <dt>{{ t('Modified by') }}</dt>
          <dd>{{ file.modifiedBy }}</dd>
        </template>

        <template v-if="file.creationTime > 0">
          <dt>{{ t('Created') }}</dt>
          <dd>{{ formatDate(file.creationTime) }}</dd>
        </template>

        <template v-if="file.createdBy">
          <dt>{{ t('Created by') }}</dt>
          <dd>{{ file.createdBy }}</dd>
        </template>

        <dt>{{ t('Folder') }}</dt>
        <dd>{{ folderOf(file) || '/' }}</dd>
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
    </NcAppSidebarTab>

    <!--
      A tab of its own so a preview is fetched only when someone asks for it.
      Rendering it beside the details meant every selection pulled a document
      down, which is wasteful while scanning a result list.
    -->
    <NcAppSidebarTab v-if="!file.isFolder" id="preview" :name="t('Preview')" :order="2">
      <template #icon>
        <NcIconSvgWrapper :path="mdiEyeOutline" :size="20" />
      </template>

      <!--
        With the Files Preview app installed, its renderer handles email,
        markdown, PDF, media and text properly. Without it, Nextcloud's own
        /core/preview thumbnail: only some types have one, so the image is shown
        optimistically and removed if it fails rather than probed first.
      -->
      <RichPreview v-if="previewOpened && richPreview" :file="file" />

      <img v-else-if="previewOpened && previewUrl && !previewFailed"
           class="details__preview"
           :src="previewUrl"
           :alt="t('Preview of {name}', { name: file.name })"
           @error="previewFailed = true">

      <NcEmptyContent v-else-if="previewOpened"
                      :name="t('No preview available')"
                      :description="t('This file type cannot be shown here.')">
        <template #icon>
          <NcIconSvgWrapper :path="mdiEyeOutline" />
        </template>
      </NcEmptyContent>
    </NcAppSidebarTab>
  </NcAppSidebar>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import {
  NcActionButton,
  NcActionCheckbox,
  NcActionLink,
  NcActionSeparator,
  NcAppSidebar,
  NcAppSidebarTab,
  NcEmptyContent,
  NcIconSvgWrapper,
} from '@nextcloud/vue'
import { mdiEyeOutline, mdiInformationOutline } from '@mdi/js'
import { generateUrl } from '@nextcloud/router'
import { useI18n } from '../composables/useI18n'
import { useSearchStore } from '../stores/searchStore'
import { usePreferencesStore } from '../stores/preferencesStore'
import { useFileCommands } from '../composables/useFileCommands'
import { folderOf, typeName } from '../filters/grouping'
import { formatDate, formatSize } from '../filters/columns'
import RichPreview from './RichPreview.vue'
import { HAS_RICH_PREVIEW } from '../constants'
import type { FileResult } from '../types/Search'

const { t } = useI18n()
const search = useSearchStore()
const preferences = usePreferencesStore()

const props = defineProps<{ file: FileResult | null }>()

const emit = defineEmits<{
  (e: 'close'): void
  (e: 'toggle-favorite', file: FileResult): void
  (e: 'changed', file: FileResult): void
}>()

const { commandsFor } = useFileCommands((file) => emit('changed', file))

/**
 * The star lives in the sidebar header, so the favorite command is left out of
 * the menu — two controls for one flag read as two different things.
 */
const commands = computed(() => (props.file ? commandsFor(props.file) : []))

// NcActions renders links and buttons alike, but they are different components,
// so the list is split rather than branched inside one v-for: a v-if/v-else
// pair inside a <template v-for> is invisible to the sidebar's own NcActions.
const linkCommands = computed(() => commands.value.filter((c) => c.href))
const buttonCommands = computed(() => commands.value.filter((c) => !c.href))

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

const richPreview = HAS_RICH_PREVIEW

/**
 * Which tab is open. Details is the default: it is what you want while scanning
 * a list, and it costs nothing to show.
 */
const activeTab = ref('details')

/** Nothing in the preview tab is built until the tab has actually been opened. */
const previewOpened = computed(() => activeTab.value === 'preview')

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
</script>

<style scoped lang="scss">
.details {
  display: grid;
  grid-template-columns: auto 1fr;
  gap: 4px 12px;
  margin: 12px 0;

  // Nextcloud's own stylesheet right-aligns every <dt>, which left the labels
  // ragged down their left edge while their values ran down a straight one.
  // Both columns start at the same edge instead.
  dt {
    text-align: start;
    color: var(--color-text-maxcontrast);
  }

  dd {
    margin: 0;
    overflow-wrap: anywhere;
  }

  // The thumbnail gets the whole tab rather than a fixed 320px box: the panel
  // already has a height, and a preview that stops halfway down it reads as a
  // failed one.
  &__preview {
    display: block;
    width: 100%;
    height: 100%;
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
}

// Two tabs stacked icon-over-label take a whole band of the panel. Laid out as
// a row they read as a switch instead of as two buttons, and give the details
// back the space.
:deep(.app-sidebar-tabs__nav) {
  padding-inline: 8px;

  ul {
    gap: 4px;
  }

  button {
    flex-direction: row;
    gap: 6px;
    min-height: 34px;
    padding-block: 2px 4px;
    font-size: 95%;
  }
}
</style>

<!--
  The panel width, set outside the scoped block on purpose: the library's own
  rule carries its scope attribute, so an equally specific rule of ours would
  win or lose on stylesheet order. The id settles it.
-->
<style lang="scss">
// The library caps the panel at 500px. That is a fair width for a column of
// key/value rows and a poor one for a document — a preview rendered in it gets
// a few words per line and reads as a sliver. This app leads with the preview,
// so it takes a wider share of the window while still leaving the result list
// wide enough to scan.
#app-sidebar-vue {
  --app-sidebar-width: clamp(300px, 32vw, 700px);
}
</style>
