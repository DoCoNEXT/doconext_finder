<template>
  <div ref="host" class="rich-preview" />
</template>

<script setup lang="ts">
/**
 * Embeds the Files Preview app's renderer, when that app is installed.
 *
 * It is reached as a custom element rather than as a Vue component on purpose:
 * each app bundles its own copy of Vue, and a component built by one runtime
 * produces vnodes the other does not understand. A custom element crosses that
 * boundary cleanly — we write plain attributes and their Vue renders.
 *
 * Built in JavaScript rather than in the template because the tag is unknown to
 * our compiler, which would warn on every render; this also makes teardown
 * explicit when the selection changes.
 */
import { onBeforeUnmount, ref, watch } from 'vue'
import { downloadUrl } from '../services/download'
import { usePreferencesStore } from '../stores/preferencesStore'
import { readableTextOn } from '../filters/colors'
import type { FileResult } from '../types/Search'

const TAG = 'doconext-file-preview'

const props = defineProps<{
  file: FileResult
  /**
   * What the search looked for, as it was typed. The renderer marks those words
   * in the document — a content search returns files whose relevance is
   * invisible until you can see where the term actually sits.
   */
  highlight?: string
}>()

const preferences = usePreferencesStore()

const host = ref<HTMLElement>()

/** The element we created, so the terms can be updated without rebuilding it. */
const preview = ref<HTMLElement>()

/**
 * Hands the renderer the colour to mark in, as the two custom properties it
 * reads. Set on the preview element itself rather than on the page, because a
 * custom property inside a `::highlight()` rule resolves against the element the
 * marked text belongs to — measured in Chrome, where an inline property on this
 * element wins and one on `<html>` would work too but would leave a setting of
 * ours declared on the whole document.
 *
 * The text colour goes with it. Every colour the picker offers can be chosen,
 * including a navy that black text disappears into, so which text reads on it is
 * ours to answer rather than the user's to discover.
 * @param element the preview element to paint
 */
function paint(element: HTMLElement) {
  const colour = preferences.highlightColor
  if (colour) {
    element.style.setProperty('--doconext-preview-highlight', colour)
    element.style.setProperty('--doconext-preview-highlight-text', readableTextOn(colour))
  } else {
    // Nothing declared: the renderer falls back to its own themed tint.
    element.style.removeProperty('--doconext-preview-highlight')
    element.style.removeProperty('--doconext-preview-highlight-text')
  }
}

/**
 * The element is defined when the other app's bundle runs, which may be after
 * ours. Waiting is why the server tells us whether that bundle was loaded at
 * all: whenDefined() on a tag nobody will ever register never resolves.
 */
let ready: Promise<void> | null = null

function whenReady(): Promise<void> {
  ready ??= customElements.whenDefined(TAG).then(() => undefined)
  return ready
}

async function render() {
  const el = host.value
  if (!el) {
    return
  }

  await whenReady()
  // The selection may have moved on while we waited.
  if (host.value !== el) {
    return
  }

  el.replaceChildren()
  const element = document.createElement(TAG)
  element.setAttribute('file-id', String(props.file.fileid))
  element.setAttribute('basename', props.file.name)
  element.setAttribute('mime', props.file.mimetype)
  element.setAttribute('source', downloadUrl(props.file))
  element.setAttribute('highlight', props.highlight ?? '')
  paint(element)
  el.appendChild(element)
  preview.value = element
}

watch(() => props.file.fileid, render, { immediate: true })
watch(host, render)

// A new search over the same open file changes what is marked but not what is
// drawn, so the attribute is written to the element that is already there
// rather than the whole preview being built again.
watch(() => props.highlight, (terms) => {
  preview.value?.setAttribute('highlight', terms ?? '')
})

// Picking a colour in the settings tab repaints an open preview rather than
// waiting for the next one.
watch(() => preferences.highlightColor, () => {
  if (preview.value) {
    paint(preview.value)
  }
})

onBeforeUnmount(() => {
  host.value?.replaceChildren()
  preview.value = undefined
})
</script>

<style scoped lang="scss">
.rich-preview {
  // The tab has a height of its own, so the preview takes all of it and scrolls
  // inside itself. A max-height in viewport units cut the document off well
  // above the foot of the panel and put a second scrollbar around the first.
  height: 100%;
  overflow: auto;

  // No card, no tint: the renderer draws a document, and a document belongs on
  // the panel's own background the way it does in the Files sidebar. The grey
  // box made every preview look like a widget dropped into the panel.
  // The element is created in script, so it never carries this component's
  // scope attribute — hence :deep. A custom element is inline by default, which
  // leaves a text-baseline gap under it and stops it filling the width.
  :deep(> *) {
    display: block;
  }
}
</style>
