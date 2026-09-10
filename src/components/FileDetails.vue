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
          <component :is="command.icon" :size="20" />
        </template>
        {{ command.label }}
      </NcActionLink>
      <NcActionButton v-for="command in buttonCommands"
                      :key="command.id"
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
            <dd class="details__copyable">
              <span>{{ entry.value }}</span>
              <NcButton class="details__copy"
                        variant="tertiary"
                        :aria-label="t('Copy {field}', { field: entry.label })"
                        :title="copiedKey === entry.key ? t('Copied') : t('Copy {field}', { field: entry.label })"
                        @click="copyValue(entry.key, entry.value)">
                <template #icon>
                  <Check v-if="copiedKey === entry.key" :size="16" />
                  <Copy v-else :size="16" />
                </template>
              </NcButton>
            </dd>
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
        <Eye :size="20" />
      </template>

      <!--
        With the Files Preview app installed, its renderer handles email,
        markdown, PDF, media and text properly. Without it, Nextcloud's own
        /core/preview thumbnail: only some types have one, so the image is shown
        optimistically and removed if it fails rather than probed first.
      -->
      <RichPreview v-if="previewOpened && richPreview" :file="file" :highlight="highlight" />

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
    child of it: NcAppSidebar has no slot at its own root, and the one place this
    has to sit is the seam between the panel and the results — which a fixed
    element finds through the same width the panel is drawn at, wherever it
    happens to live in the tree.

    `separator` with a value and limits is the ARIA window splitter, so the
    keyboard gets the control the pointer has rather than a div it cannot reach.
  -->
  <div class="grip"
       role="separator"
       aria-orientation="vertical"
       tabindex="0"
       :aria-label="t('Resize the details panel')"
       :aria-valuenow="resize.width.value"
       :aria-valuemin="resize.minWidth"
       :aria-valuemax="resize.maxWidth"
       :title="t('Drag to resize the panel. Double-click to fit it to the window again.')"
       :class="{ 'grip--active': resize.resizing.value }"
       @pointerdown="resize.startResize"
       @pointermove="resize.resizeTo"
       @pointerup="resize.endResize"
       @pointercancel="resize.endResize"
       @keydown="resize.nudge"
       @dblclick="resize.resetWidth" />
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import {
  NcActionButton,
  NcActionLink,
  NcAppSidebar,
  NcAppSidebarTab,
  NcButton,
  NcEmptyContent,
} from '@nextcloud/vue'
import { Check, Copy, Eye, Info } from '@lucide/vue'
import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import { useI18n } from '../composables/useI18n'
import { useClipboard } from '../composables/useClipboard'
import { useSearchStore } from '../stores/searchStore'
import { useFileCommands } from '../composables/useFileCommands'
import { useSidebarResize } from '../composables/useSidebarResize'
import { folderOf, typeName } from '../filters/grouping'
import { formatDate, formatSize } from '../filters/columns'
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
.details {
  display: grid;
  // Nextcloud's sidebar gives every <dt> a fixed 130px with `white-space:
  // nowrap`, so a longer label than that does not wrap — it paints straight
  // over its own value ("Advocaat wederpartij" across "mr. J. Sanders").
  // fit-content lets the label column take what it needs and no more than
  // nearly half the panel; the dt rule below undoes the nowrap.
  grid-template-columns: fit-content(45%) 1fr;
  gap: 4px 12px;
  margin: 12px 0;

  // Nextcloud's own stylesheet right-aligns every <dt>, which left the labels
  // ragged down their left edge while their values ran down a straight one.
  // Both columns start at the same edge instead.
  dt {
    text-align: start;
    color: var(--color-text-maxcontrast);
    // Both from Nextcloud's own sidebar stylesheet; see the grid above.
    width: auto;
    white-space: normal;
    overflow-wrap: anywhere;
  }

  dd {
    margin: 0;
    overflow-wrap: anywhere;
  }

  // A value and its copy button share the row; the button only appears for the
  // row being pointed at or tabbed to, so a list of a dozen fields is not a
  // list of a dozen buttons.
  &__copyable {
    display: flex;
    align-items: start;
    gap: 4px;
  }

  &__copy {
    opacity: 0;
    flex: 0 0 auto;
    // NcButton's own 34px would set the height of every metadata row.
    min-height: 24px !important;
    min-width: 24px !important;
    height: 24px;
    width: 24px;
  }

  &__copyable:hover &__copy,
  &__copy:focus-visible {
    opacity: 1;
  }

  // Touch has no hover, so there is nothing to reveal the button — show it.
  @media (hover: none) {
    &__copy {
      opacity: 1;
    }
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
  The panel width, set outside the scoped block on purpose: the library's own
  rule carries its scope attribute, so an equally specific rule of ours would
  win or lose on stylesheet order. The id settles it. The grip is here too
  because it is fixed to the viewport — a scoped rule would style it fine, but
  it belongs with the width it moves.
-->
<style lang="scss">
// Where the panel starts, before anybody drags it. The library caps it at
// 500px, which is a fair width for a column of key/value rows and a poor one
// for a document — a preview rendered in it gets a few words per line and
// reads as a sliver. This app leads with the preview, so it opens wider.
// A default, not a decision: useSidebarResize.ts overrides this property inline
// on <html> once a width has been dragged, and removing it again hands the
// panel back to this rule, window-relative as it started.
:root {
  --dcn-sidebar-width: clamp(300px, 32vw, 700px);
}

// Under the library's own phone breakpoint the panel covers the window instead
// of sitting beside the results, and a width — ours or a dragged one — would
// leave a strip of unreachable page next to it.
@media only screen and (min-width: 513px) {
  #app-sidebar-vue {
    --app-sidebar-width: var(--dcn-sidebar-width);
  }
}

// A drag reads as a drag, not as an attempt to select the file names it passes
// over. Set on <html> for the length of the gesture, since the pointer is
// captured by the grip and the selection would otherwise be made in whatever it
// travels across.
.dcn-sidebar-resizing,
.dcn-sidebar-resizing * {
  user-select: none;
  cursor: col-resize !important;
}

// Positioned over the seam rather than placed in the flow: the panel is the
// library's to lay out, and an element inserted beside it would change the
// layout it computes. Absolute against #content-vue — this component's own parent, and the box the
// panel fills top to bottom — so `inset-block: 0` is exactly the panel's height
// with nothing to measure. Fixed positioning happens to land in the same place,
// because #content-vue carries `backdrop-filter: blur(25px)` and a backdrop
// filter makes an element a containing block for fixed descendants just as
// `filter` does. That is an accident of the library's theming, though: it cost
// an hour of a grip sitting 50px too low, because `top: var(--header-height)`
// was then measured from a box already below the header.
.grip {
  position: absolute;
  z-index: 1501; // one above .app-sidebar, or the panel's own edge takes the press
  inset-block: 0;
  inset-inline-end: var(--app-sidebar-width, var(--dcn-sidebar-width));
  // Wider than it looks: a 2px seam is a target nobody hits. The negative margin
  // centres the grab area on the seam instead of putting it beside it.
  inline-size: 9px;
  margin-inline-end: -4px;
  cursor: col-resize;
  background: transparent;

  // What lights up is a line down the seam, not the whole grab area: the target
  // is nine pixels wide because a two-pixel one cannot be hit, but a nine-pixel
  // bar of colour reads as a panel of its own rather than as the edge it moves.
  &::after {
    content: '';
    position: absolute;
    inset-block: 0;
    // Centred without a transform, which would have to know the writing
    // direction to point the right way.
    inset-inline-start: calc(50% - 1px);
    inline-size: 2px;
    background: transparent;
    transition: background-color var(--animation-quick);
  }

  &:hover::after,
  &:focus-visible::after,
  &--active::after {
    background: var(--color-primary-element);
  }

  // The line is the focus ring too: an outline around a nine-pixel column is a
  // blue sliver that says nothing about what has focus.
  &:focus-visible {
    outline: none;
  }

  // Below the breakpoint the panel covers the window, so there is no seam to
  // drag — and a strip of col-resize down the middle of a phone screen is only
  // in the way.
  @media only screen and (max-width: 512px) {
    display: none;
  }
}
</style>
