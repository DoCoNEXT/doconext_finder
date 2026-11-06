<template>
  <NcAppSidebar v-if="file"
                :name="file.name"
                :subname="folderOf(file) || '/'"
                :active="activeTab"
                :starred="file.favorite"
                @update:starred="$emit('toggleFavorite', file)"
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
                    :target="command.target"
                    close-after-click>
        <template #icon>
          <component :is="command.icon" :size="20" />
        </template>
        {{ command.label }}
      </NcActionLink>
      <NcActionButton v-for="command in buttonCommands"
                      :key="command.id"
                      close-after-click
                      @click="command.run?.()">
        <template #icon>
          <component :is="command.icon" :size="20" />
        </template>
        {{ command.label }}
      </NcActionButton>
    </template>

    <NcAppSidebarTab id="details" :name="t('Details')" :order="1">
      <template #icon>
        <Info :size="20" />
      </template>

      <!--
        Drawn the way Core draws a file's metadata (its sidebar tab and a
        record's Details tab): a small-caps heading per group, and every field a
        grey label with its icon above the value. A two-column table squeezed
        long labels against their values, and a field read differently here than
        it did one click away in Core.
      -->
      <section class="details">
        <h4 class="details__heading">
          <Info :size="14" />
          {{ t('Details') }}
        </h4>
        <div class="details__fields">
          <div v-for="entry in general" :key="entry.key" class="details__field">
            <span class="details__label">
              <component :is="entry.icon" :size="14" />
              <span>{{ entry.label }}</span>
            </span>
            <span class="details__value" :class="{ 'details__value--mono': entry.mono }">{{ entry.value }}</span>
          </div>
        </div>
      </section>

      <!--
        Everything the server holds for this file, whichever app put it there —
        Core's fields when Core is installed. Labels come from the same registry
        the columns use, so a field reads the same wherever it appears.
      -->
      <section v-if="metadata.length" class="details details--ruled">
        <h4 class="details__heading">
          <Tag :size="14" />
          {{ t('Metadata') }}
        </h4>
        <div class="details__fields">
          <div v-for="entry in metadata" :key="entry.key" class="details__field">
            <span class="details__label">
              <component :is="entry.icon" :size="14" />
              <span>{{ entry.label }}</span>
            </span>
            <span class="details__value details__copyable">
              <span>{{ entry.value }}</span>
              <NcButton class="details__copy"
                        variant="tertiary"
                        :aria-label="t('Copy {field}', { field: entry.label })"
                        :title="copiedKey === entry.key ? t('Copied') : t('Copy {field}', { field: entry.label })"
                        @click="copyValue(entry.key, entry.value)">
                <template #icon>
                  <Check v-if="copiedKey === entry.key" :size="14" />
                  <Copy v-else :size="14" />
                </template>
              </NcButton>
            </span>
          </div>
        </div>
      </section>
    </NcAppSidebarTab>

    <!--
      A tab of its own so a preview is fetched only when someone asks for it.
      Rendering it beside the details meant every selection pulled a document
      down, which is wasteful while scanning a result list.
    -->
    <NcAppSidebarTab v-if="!file.isFolder" id="preview" :name="t('Preview')" :order="2">
      <template #icon>
        <Eye :size="20" />
      </template>

      <!--
        With the Files Preview app installed, its renderer handles email,
        markdown, PDF, media and text properly. Without it, Nextcloud's own
        /core/preview thumbnail: only some types have one, so the image is shown
        optimistically and removed if it fails rather than probed first.
      -->
      <RichPreview v-if="previewOpened && richPreview" :file="file" :highlight="highlight" />

      <NcEmptyContent v-else-if="previewOpened && !canRead(file)"
                      :name="t('No preview available')"
                      :description="t('You do not have permission to view this file.')">
        <template #icon>
          <Eye />
        </template>
      </NcEmptyContent>

      <img v-else-if="previewOpened && previewUrl && !previewFailed"
           class="details__preview"
           :src="previewUrl"
           :alt="t('Preview of {name}', { name: file.name })"
           @error="previewFailed = true">

      <NcEmptyContent v-else-if="previewOpened"
                      :name="t('No preview available')"
                      :description="t('This file type cannot be shown here.')">
        <template #icon>
          <Eye />
        </template>
      </NcEmptyContent>
    </NcAppSidebarTab>
  </NcAppSidebar>

  <!--
    Open with nothing selected — after a fresh search, or before the first click.
    The panel holds its place rather than appearing and disappearing under the
    results, which is what made its width jump around while you worked.
  -->
  <NcAppSidebar v-else :name="t('Details')" @close="$emit('close')">
    <NcEmptyContent :name="t('Nothing selected')"
                    :description="t('Click a result to see everything the server knows about it.')">
      <template #icon>
        <Info />
      </template>
    </NcEmptyContent>
  </NcAppSidebar>

  <!--
    The panel's leading edge, as a control. A sibling of the panel rather than a
    child of it: NcAppSidebar has no slot at its own root. See
    styles/sidebar-resize.scss for what this element's parent has to be.

    `separator` with a value and limits is the ARIA window splitter, so the
    keyboard gets the control the pointer has rather than a div it cannot reach.
  -->
  <div class="dcn-sidebar-grip"
       role="separator"
       aria-orientation="vertical"
       tabindex="0"
       :aria-label="t('Resize the details panel')"
       :aria-valuenow="resize.width.value"
       :aria-valuemin="resize.minWidth"
       :aria-valuemax="resize.maxWidth"
       :title="t('Drag to resize the panel. Double-click to fit it to the window again.')"
       :class="{ 'dcn-sidebar-grip--active': resize.resizing.value }"
       @pointerdown="resize.startResize"
       @pointermove="resize.resizeTo"
       @pointerup="resize.endResize"
       @pointercancel="resize.endResize"
       @keydown="resize.nudge"
       @dblclick="resize.resetWidth" />
</template>

<script setup lang="ts">
import { computed, ref, watch, type Component } from 'vue'
import {
  NcActionButton,
  NcActionLink,
  NcAppSidebar,
  NcAppSidebarTab,
  NcButton,
  NcEmptyContent,
} from '@nextcloud/vue'
import {
  CalendarPlus,
  Check,
  CircleCheck,
  Clock,
  Copy,
  Eye,
  File,
  FileCode,
  FileText,
  Folder,
  HardDrive,
  Hash,
  Info,
  Tag,
  User,
  UserPen,
} from '@lucide/vue'
import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import { useI18n } from '../composables/useI18n'
import { useClipboard } from '../composables/useClipboard'
import { useSearchStore } from '../stores/searchStore'
import { useFileCommands } from '../composables/useFileCommands'
import { useSidebarResize } from '../composables/useSidebarResize'
import { folderOf, typeName } from '../filters/grouping'
import { formatDate, formatSize } from '../filters/columns'
import { canRead } from '../filters/access'
import RichPreview from './RichPreview.vue'
import { HAS_RICH_PREVIEW } from '../constants'
import type { FileResult } from '../types/Search'

const { t } = useI18n()
const search = useSearchStore()

/**
 * Kept as one object rather than destructured: the template binds handlers and
 * two refs off it, and `resize.width.value` in a binding says plainly that the
 * number comes from a composable and not from a prop of this component.
 */
const resize = useSidebarResize()

const props = defineProps<{
  file: FileResult | null
  /** The words the search looked for; the preview marks them in the document. */
  highlight?: string
}>()

const emit = defineEmits<{
  (e: 'close'): void
  (e: 'toggleFavorite', file: FileResult): void
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

interface DetailRow {
  key: string
  label: string
  value: string
  icon: Component
  mono?: boolean
}

/** What every file has, whatever app put metadata on it. */
const general = computed<DetailRow[]>(() => {
  const file = props.file
  if (!file) {
    return []
  }
  const rows: DetailRow[] = [
    { key: 'type', label: t('Type'), value: typeName(t, file), icon: File },
    { key: 'mimetype', label: t('Media type'), value: file.isFolder ? t('Folder') : file.mimetype, icon: FileCode, mono: !file.isFolder },
    { key: 'size', label: t('Size'), value: file.isFolder ? t('Folder') : formatSize(file.size), icon: HardDrive },
    { key: 'modified', label: t('Modified'), value: formatDate(file.mtime), icon: Clock },
  ]
  if (file.modifiedBy) {
    rows.push({ key: 'modifiedBy', label: t('Modified by'), value: file.modifiedBy, icon: UserPen })
  }
  if (file.creationTime > 0) {
    rows.push({ key: 'created', label: t('Created'), value: formatDate(file.creationTime), icon: CalendarPlus })
  }
  if (file.createdBy) {
    rows.push({ key: 'createdBy', label: t('Created by'), value: file.createdBy, icon: User })
  }
  rows.push({ key: 'folder', label: t('Folder'), value: folderOf(file) || '/', icon: Folder })
  return rows
})

/**
 * Core marks each label with its field type. Finder knows only how a value is
 * stored, which is enough to tell a number or a yes/no from text.
 *
 * @param type the stored type, as the metadata registry reports it
 */
function iconForType(type: string | undefined): Component {
  if (type === 'int' || type === 'float' || type === 'int[]') {
    return Hash
  }
  if (type === 'bool') {
    return CircleCheck
  }
  return FileText
}

/**
 * Only the keys this file actually carries, labelled and sorted, so the panel
 * shows what is there rather than a hundred mostly-empty rows.
 */
const metadata = computed<DetailRow[]>(() => {
  const values = props.file?.metadata ?? {}
  const known = search.metadata

  return Object.entries(values)
    .map(([key, value]) => {
      const field = known.find((f) => f.key === key)
      return {
        key,
        value,
        label: field?.label ?? key,
        icon: iconForType(field?.type),
      }
    })
    .sort((a, b) => a.label.localeCompare(b.label))
})

const { copy } = useClipboard()

/** Which row last copied, so only that row's button shows the tick. */
const copiedKey = ref('')

/**
 * @param key the metadata key whose button was pressed
 * @param value what to put on the clipboard
 */
async function copyValue(key: string, value: string) {
  if (await copy(value)) {
    copiedKey.value = key
    setTimeout(() => {
      if (copiedKey.value === key) {
        copiedKey.value = ''
      }
    }, 2000)
  } else {
    showError(t('Could not copy to the clipboard'))
  }
}

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
// The measures of Core's metadata tab (DcnCoreFileSidebarTab), so a field
// looks the same in both apps.
.details {
  margin: 12px 0 16px;

  // Groups are told apart by a rule and their icon, as in Core.
  &--ruled {
    padding-top: 12px;
    border-top: 1px solid var(--color-border);
  }

  &__heading {
    display: flex;
    align-items: center;
    gap: 6px;
    margin: 0 0 10px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--color-main-text);
  }

  &__fields {
    display: flex;
    flex-direction: column;
    gap: 12px;
  }

  &__field {
    display: flex;
    flex-direction: column;
    gap: 3px;
    min-width: 0;
  }

  &__label {
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: 12px;
    font-weight: 500;
    color: var(--color-text-maxcontrast);

    svg {
      flex: 0 0 auto;
    }
  }

  // Indented past the label's icon, so values run down the label text's edge.
  &__value {
    padding-inline-start: 19px;
    font-size: 14px;
    color: var(--color-main-text);
    overflow-wrap: anywhere;

    &--mono {
      font-family: monospace;
      font-size: 13px;
    }
  }

  // The button sits right after the value, not pinned to the far edge a
  // column away from what it copies.
  &__copyable {
    display: flex;
    align-items: center;
    gap: 2px;
  }

  &__copy {
    flex: 0 0 auto;
    // NcButton's own 34px would set the height of every metadata row.
    min-height: 24px !important;
    min-width: 24px !important;
    height: 24px;
    width: 24px;
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
}

// Two tabs stacked icon-over-label take a whole band of the panel, so they are
// laid out as a row — but the library's tab is a button that fills half the
// nav, marks the selected one with a grey fill, and underlines it with a 4px
// bar sized at 80% of that half, which lands nowhere near the label it points
// at. Drawn here as tabs instead: each as wide as its own label, on a shared
// rule, with a hairline under the one you are on.
// Every rule carries .button-vue on purpose. The library's own are
// `.tab:not(.legacy).selected`, which ties with a nav-plus-tab selector on
// specificity — and a tie is settled by which stylesheet loaded last, which is
// the library's, since the sidebar arrives in a chunk of its own.
:deep(.app-sidebar-tabs__nav) {
  gap: 2px;
  padding: 0 8px;
  border-bottom: 1px solid var(--color-border);

  .app-sidebar-tabs__tab.button-vue {
    flex: 0 1 auto;
    flex-direction: row;
    // The label is a plain block that stretches to the tab's full height and
    // then draws its text at the top of it, while the icon sits in a flex box
    // of its own and centres itself — which is why the two did not line up.
    align-items: center;
    gap: 6px;
    min-width: 0;
    height: 38px;
    min-height: 38px;
    padding: 0 10px;
    border-radius: var(--border-radius) var(--border-radius) 0 0;
    background-color: transparent;
    color: var(--color-text-maxcontrast);
    font-size: 95%;
    font-weight: 500;

    &:hover {
      background-color: var(--color-background-hover);
      color: var(--color-main-text);
    }

    // A hairline sitting on the nav's border instead of a bar floating above
    // it. The library's zero width and its slide-out are left alone: that is
    // the animation, and it still reads right at this height.
    &::after {
      bottom: -1px;
      height: 2px;
      border-radius: 2px 2px 0 0;
    }
  }

  .app-sidebar-tabs__tab.button-vue[aria-selected='true'] {
    background-color: transparent;
    color: var(--color-primary-element);

    &:hover {
      background-color: var(--color-background-hover);
    }

    // The full width of the tab, because the tab is what it points at.
    &::after {
      width: 100%;
    }
  }
}

</style>

<!--
  Set outside the scoped block on purpose: every selector the shared stylesheet
  carries is global — NcAppSidebar's own root, and a class that lands on <html>
  — so a scope attribute would stop all of them matching.
-->
<style lang="scss">
// The handle and the width it moves, shared with the other DoCoNEXT apps, so it
// lives in a stylesheet of its own rather than in this component.
@use '../styles/sidebar-resize.scss';

// Where the panel opens. The library caps it at 500px, which is a fair width for
// a column of key/value rows and a poor one for a document — a preview rendered
// in it gets a few words per line and reads as a sliver. This app leads with the
// preview, so it opens wider than the other apps do.

// An opening width, not a decision: it is what the panel falls back to until
// somebody drags it, and what a double-click on the handle returns it to.
:root {
  --dcn-sidebar-width-initial: clamp(300px, 32vw, 700px);
}
</style>
