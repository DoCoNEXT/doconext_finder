<template>
  <div class="folder-scope"
       :title="disabled ? t('Clear the workspace scope to search a folder instead') : t('Search inside one folder')">
    <!--
      Drawn as a field, not a button: it holds a value — where the search looks —
      and a button among the selects read as something to do rather than
      something set. Read-only because the value comes from the picker, which is
      how NcSelect draws its own box too, so the two share a border and a label.
    -->
    <NcTextField :model-value="chosen?.label ?? t('Anywhere')"
                 class="folder-scope__field"
                 :label="t('Folder')"
                 readonly
                 aria-haspopup="dialog"
                 :disabled="disabled"
                 :show-trailing-button="chosen !== null"
                 :trailing-button-label="t('Search everywhere again')"
                 @click="browse"
                 @keydown.enter.prevent="browse"
                 @keydown.space.prevent="browse"
                 @trailing-button-click="clear">
      <template #icon>
        <FolderOpen :size="20" />
      </template>
    </NcTextField>
  </div>
</template>

<script setup lang="ts">
/**
 * Narrows a search to one folder, chosen from Nextcloud's own file picker.
 *
 * A picker rather than a dropdown of top-level folders: the folder someone wants
 * to search is as often three levels down as at the root, and the picker is the
 * control they already know from every other "where does this go" question in
 * Nextcloud.
 *
 * Sits with Type and Modified rather than with the DoCoNEXT fields below,
 * because it is the one scope that asks nothing of any other app.
 */
import { computed } from 'vue'
import { NcTextField } from '@nextcloud/vue'
import { FolderOpen } from '@lucide/vue'
import { getFilePickerBuilder } from '@nextcloud/dialogs'
import { useI18n } from '../composables/useI18n'
import { useSearchStore } from '../stores/searchStore'

/** The bits of a picked node this needs; the picker returns a good deal more. */
interface PickedNode {
  fileid?: number
  basename?: string
  path?: string
}

const { t } = useI18n()

/**
 * The picker renders no confirm button of its own — without a factory it shows
 * none at all, which is a dialog you can only cancel.
 *
 * The nodes handed to the factory are already the answer: with directories
 * allowed and nothing selected, the picker offers the folder you have navigated
 * into, and otherwise the row you clicked. So the label can name the folder that
 * is actually about to be chosen, and the callback has nothing left to do —
 * pickNodes() resolves with the same nodes.
 * @param nodes what the picker would return if this button were pressed
 */
function confirmButton(nodes: PickedNode[]) {
  return [{
    label: nodes[0]?.basename
      ? t('Search in {folder}', { folder: nodes[0].basename })
      : t('Search here'),
    variant: 'primary' as const,
    callback: () => {},
  }]
}

const store = useSearchStore()

const chosen = computed(() => (store.query.scope?.level === 'folder' ? store.query.scope : null))

/**
 * A folder and a workspace are alternatives, not a cascade — see FileScope. So
 * whichever the user answered first holds the scope until they clear it, and the
 * other side says why it is unavailable rather than silently doing nothing.
 */
const disabled = computed(() => store.query.scope !== null && store.query.scope.level !== 'folder')

async function browse() {
  const picker = getFilePickerBuilder(t('Search inside which folder?'))
    .setMultiSelect(false)
    .setMimeTypeFilter(['httpd/unix-directory'])
    .allowDirectories(true)
    // No "New folder": this dialog asks where to look, and offering to create
    // something while answering that is a different verb altogether.
    .setNoMenu(true)
    .setButtonFactory(confirmButton)
    .build()

  let picked: PickedNode | undefined
  try {
    const nodes = await picker.pickNodes() as PickedNode[]
    picked = nodes[0]
  } catch {
    return // the picker was dismissed, which is not an answer
  }

  if (picked?.fileid === undefined) {
    return
  }

  store.query.scope = {
    level: 'folder',
    id: picked.fileid,
    // The name alone, not the path: the field is narrow and the last segment is
    // what people recognise. The full path is in the picker they just used.
    label: picked.basename || picked.path || t('Folder'),
  }
}

function clear() {
  store.query.scope = null
}
</script>

<style scoped lang="scss">
/*
 * A grid item, so the grid decides the width; the name inside ellipsizes on its
 * own, as any input's value does. The bottom margin is NcSelect's: the grid
 * aligns the fields on their margin boxes, so without it this border sat 4px
 * below theirs (measured).
 */
.folder-scope {
  min-width: 0;
  margin-bottom: 4px;
}

/* A click opens the picker; the text cursor of an input would promise typing. */
.folder-scope__field :deep(input:not(:disabled)) {
  cursor: pointer;
}

/*
 * The picker hands focus back to the field when it closes, and the browser then
 * marks the whole name as selected — which made a value that cannot be typed
 * over look like it was about to be.
 */
.folder-scope__field :deep(input::selection) {
  background: transparent;
}
</style>
