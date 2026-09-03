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
import type { FileResult } from '../types/Search'

const TAG = 'doconext-file-preview'

const props = defineProps<{ file: FileResult }>()

const host = ref<HTMLElement>()

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
  const preview = document.createElement(TAG)
  preview.setAttribute('file-id', String(props.file.fileid))
  preview.setAttribute('basename', props.file.name)
  preview.setAttribute('mime', props.file.mimetype)
  preview.setAttribute('source', downloadUrl(props.file))
  el.appendChild(preview)
}

watch(() => props.file.fileid, render, { immediate: true })
watch(host, render)

onBeforeUnmount(() => host.value?.replaceChildren())
</script>

<style scoped lang="scss">
.rich-preview {
  // The renderer sizes itself; this keeps a tall document from pushing the rest
  // of the panel out of reach.
  max-height: 60vh;
  overflow: auto;
  border-radius: var(--border-radius-large);
  background: var(--color-background-dark);
}
</style>
