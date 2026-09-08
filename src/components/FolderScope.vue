<template>
  <div class="folder-scope">
    <span class="folder-scope__label">{{ t('Folder') }}</span>

    <div class="folder-scope__row">
      <NcButton class="folder-scope__pick"
                variant="secondary"
                :disabled="disabled"
                :title="disabled ? t('Clear the workspace scope to search a folder instead') : t('Search inside one folder')"
                @click="browse">
        <template #icon>
          <FolderOpen :size="20" />
        </template>
        <span class="folder-scope__name">{{ chosen?.label ?? t('Anywhere') }}</span>
      </NcButton>

      <NcButton v-if="chosen"
                variant="tertiary"
                :aria-label="t('Search everywhere again')"
                :title="t('Search everywhere again')"
                @click="clear">
        <template #icon>
          <X :size="20" />
        </template>
      </NcButton>
    </div>
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
import { NcButton } from '@nextcloud/vue'
import { FolderOpen, X } from '@lucide/vue'
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
 * Three measurements taken from a rendered page decide this block, because the
 * field has to sit flush with the NcSelects beside it and the row aligns on the
 * bottom — so every one of them shows up as a visible step if it is off.
 *
 * 1. NcSelect writes its label inside its own box as `display: block;
 *    margin-bottom: 2px` at the inherited size. A smaller label looked right on
 *    its own and pushed everything below it out of line.
 * 2. NcSelect carries a 4px bottom margin, and flex aligns margin boxes, so
 *    without the same margin this control's border sat 4px lower than theirs.
 * 3. Its control is not the clickable area but the clickable area *plus* its own
 *    border: 36px against an NcButton's 34.
 */
.folder-scope {
  margin-bottom: 4px;
  /*
   * Sizes itself rather than borrowing the row's .finder__preset, which sets a
   * 170px minimum at the same specificity and simply won on source order.
   *
   * The 260px is NcSelect's own minimum: it beats the 220px basis every field in
   * these rows declares, so every select renders 260 wide. A narrower folder
   * field left the two rows starting their second column at different places.
   */
  flex: 0 1 220px;
  min-width: 260px;
}

.folder-scope__label {
  display: block;
  margin-bottom: 2px;
}

.folder-scope__row {
  display: flex;
  align-items: center;
  gap: 2px;
}

.folder-scope__pick,
.folder-scope__row :deep(.button-vue) {
  min-height: calc(var(--default-clickable-area) + 2 * var(--border-width-input));
}

.folder-scope__pick {
  flex: 1 1 auto;
  min-width: 0;
}

/* A deep path or a long folder name must not stretch the filter row. */
.folder-scope__name {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
</style>
