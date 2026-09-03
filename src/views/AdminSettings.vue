<template>
  <NcSettingsSection :name="PRODUCT_NAME"
                     :description="t('How files open on people\'s own machines.')">
    <p class="hint">
      {{ t('Most files are handed to the Nextcloud desktop client, which opens the synced copy so edits sync back. The formats below go to DoCoNEXT Bridge instead — for files no local application can open as they are, such as Outlook messages, which it converts first.') }}
    </p>

    <NcTextField :model-value="draft"
                 class="field"
                 :label="t('Extensions handled by DoCoNEXT Bridge')"
                 :label-outside="true"
                 placeholder="eml, msg"
                 @update:model-value="onInput" />

    <p class="hint">
      {{ t('Comma-separated. Leave empty to send everything to the desktop client; the Bridge is then not needed at all.') }}
    </p>

    <NcButton variant="primary" :disabled="saving" @click="save">
      {{ saving ? t('Saving…') : t('Save') }}
    </NcButton>

    <NcNoteCard v-if="notice" :type="noticeType">{{ notice }}</NcNoteCard>
  </NcSettingsSection>

  <NcSettingsSection :name="t('File type filters')"
                     :description="t('Which categories the search page\'s Type filter offers, and in what order.')">
    <table class="filter-table">
      <thead>
        <tr>
          <th scope="col">{{ t('Category') }}</th>
          <th scope="col" class="filter-table__origin-column">{{ t('Origin') }}</th>
          <th scope="col" class="filter-table__actions-column">{{ t('Order') }}</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="(item, index) in filterDraft" :key="item.key">
          <td>
            <div class="filter-table__name">
              {{ item.type === 'builtin' ? builtinDefinitions.get(item.id)?.label ?? item.id : item.label }}
            </div>
            <div class="filter-table__mimetypes">{{ mimetypesFor(item).join(', ') }}</div>
          </td>
          <td class="filter-table__origin filter-table__origin-column">
            {{ item.type === 'builtin' ? t('Built-in') : t('Custom') }}
          </td>
          <td class="filter-table__actions-column">
            <div class="filter-table__actions">
              <NcButton variant="tertiary"
                        :disabled="index === 0"
                        :aria-label="t('Move up')"
                        @click="move(index, -1)">
                <template #icon>
                  <NcIconSvgWrapper :path="mdiArrowUp" :size="18" />
                </template>
              </NcButton>
              <NcButton variant="tertiary"
                        :disabled="index === filterDraft.length - 1"
                        :aria-label="t('Move down')"
                        @click="move(index, 1)">
                <template #icon>
                  <NcIconSvgWrapper :path="mdiArrowDown" :size="18" />
                </template>
              </NcButton>
              <NcButton variant="tertiary" :aria-label="t('Remove')" @click="removeAt(index)">
                <template #icon>
                  <NcIconSvgWrapper :path="mdiClose" :size="18" />
                </template>
              </NcButton>
            </div>
          </td>
        </tr>
      </tbody>
    </table>

    <div v-if="hiddenBuiltins.length" class="filter-list__hidden">
      <span class="muted">{{ t('Hidden built-in categories:') }}</span>
      <NcButton v-for="hidden in hiddenBuiltins"
                :key="hidden.id"
                variant="secondary"
                @click="addBuiltin(hidden.id)">
        + {{ hidden.label }}
      </NcButton>
    </div>

    <h4>{{ t('Add a custom category') }}</h4>
    <p class="hint">
      {{ t('A custom category\'s label is shown exactly as typed, in every language — it is not translated the way the built-in categories are.') }}
    </p>

    <NcTextField v-model="customLabel"
                 class="field"
                 :label="t('Label')"
                 :label-outside="true"
                 :placeholder="t('CAD drawings')" />
    <NcTextField v-model="customMimetypes"
                 class="field"
                 :label="t('Mimetypes')"
                 :label-outside="true"
                 placeholder="application/x-dwg, application/dxf" />
    <p class="hint">
      {{ t('Comma-separated. Exact mimetypes, or a single trailing wildcard like image/%.') }}
    </p>
    <NcButton :disabled="!canAddCustom" @click="addCustom">
      {{ t('Add category') }}
    </NcButton>

    <NcButton variant="primary" class="filter-list__save" :disabled="savingFilters" @click="saveFilters">
      {{ savingFilters ? t('Saving…') : t('Save') }}
    </NcButton>

    <NcNoteCard v-if="filtersNotice" :type="filtersNoticeType">{{ filtersNotice }}</NcNoteCard>
  </NcSettingsSection>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { NcButton, NcIconSvgWrapper, NcNoteCard, NcSettingsSection, NcTextField } from '@nextcloud/vue'
import { mdiArrowDown, mdiArrowUp, mdiClose } from '@mdi/js'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { useI18n } from '../composables/useI18n'
import { builtinFileTypeDefinitions } from '../filters/presets'
import { BRIDGE_EXTENSIONS, FILE_TYPE_FILTERS, PRODUCT_NAME } from '../constants'
import type { FileTypeFilterEntry } from '../types/Search'

const { t } = useI18n()

const draft = ref(BRIDGE_EXTENSIONS.join(', '))
const saving = ref(false)
const notice = ref('')
const noticeType = ref<'success' | 'error'>('success')

function onInput(value: string | number) {
  draft.value = String(value)
  notice.value = ''
}

async function save() {
  saving.value = true
  try {
    const { data } = await axios.put<{ extensions: string[] }>(
      generateUrl('/apps/doconext_finder/api/admin/bridge-extensions'),
      { extensions: draft.value },
    )
    // Show what was stored, not what was typed: the server lower-cases, strips
    // dots and drops duplicates, and the admin should see the result.
    draft.value = data.extensions.join(', ')
    noticeType.value = 'success'
    notice.value = data.extensions.length
      ? t('Saved. Open a page again for the change to take effect.')
      : t('Saved. Everything now goes to the Nextcloud desktop client.')
  } catch (error) {
    noticeType.value = 'error'
    notice.value = (error as Error).message
  } finally {
    saving.value = false
  }
}

/** Label + mimetypes for the builtin categories, keyed by id — resolved client-side for translation. */
const builtinDefinitions = computed(() => new Map(
  builtinFileTypeDefinitions(t).map((preset) => [preset.id, preset]),
))

/**
 * A v-for key that survives reordering; a not-yet-saved custom entry has no
 * server-assigned id yet, so the id alone isn't enough.
 */
type DraftEntry = FileTypeFilterEntry & { key: string }

/**
 * The mimetype expression shown, read-only, beside every row — builtin or custom.
 * @param item
 */
function mimetypesFor(item: DraftEntry): string[] {
  return item.type === 'custom' ? item.mimetypes : builtinDefinitions.value.get(item.id)?.mimetypes ?? []
}

let nextKey = 0
function withKey(entry: FileTypeFilterEntry): DraftEntry {
  return { ...entry, key: `${entry.type}:${entry.id}:${nextKey++}` }
}

const filterDraft = ref<DraftEntry[]>(FILE_TYPE_FILTERS.map(withKey))

const hiddenBuiltins = computed(() => {
  const shown = new Set(filterDraft.value.filter((entry) => entry.type === 'builtin').map((entry) => entry.id))

  return [...builtinDefinitions.value.values()]
    .filter((preset) => !shown.has(preset.id))
})

function move(index: number, direction: -1 | 1) {
  const to = index + direction
  if (to < 0 || to >= filterDraft.value.length) {
    return
  }
  const [entry] = filterDraft.value.splice(index, 1)
  filterDraft.value.splice(to, 0, entry!)
}

function removeAt(index: number) {
  filterDraft.value.splice(index, 1)
}

function addBuiltin(id: string) {
  filterDraft.value.push(withKey({ type: 'builtin', id }))
}

const customLabel = ref('')
const customMimetypes = ref('')
const canAddCustom = computed(() => customLabel.value.trim() !== '' && customMimetypes.value.trim() !== '')

function addCustom() {
  const label = customLabel.value.trim()
  const mimetypes = customMimetypes.value.split(',').map((m) => m.trim()).filter(Boolean)
  if (label === '' || mimetypes.length === 0) {
    return
  }
  // The server assigns the real id (a slug of the label) on save; this one is
  // only for tracking the row in the editor until then.
  filterDraft.value.push(withKey({ type: 'custom', id: `pending-${nextKey}`, label, mimetypes }))
  customLabel.value = ''
  customMimetypes.value = ''
}

const savingFilters = ref(false)
const filtersNotice = ref('')
const filtersNoticeType = ref<'success' | 'error'>('success')

async function saveFilters() {
  savingFilters.value = true
  try {
    const { data } = await axios.put<{ filters: FileTypeFilterEntry[] }>(
      generateUrl('/apps/doconext_finder/api/admin/file-type-filters'),
      { filters: filterDraft.value.map(({ key, ...entry }) => entry) },
    )
    // Show what was stored, not what was typed: unknown builtin ids, unusable
    // custom entries and duplicate ids are dropped server-side.
    filterDraft.value = data.filters.map(withKey)
    filtersNoticeType.value = 'success'
    filtersNotice.value = t('Saved. People will see the new list next time they open Finder.')
  } catch (error) {
    filtersNoticeType.value = 'error'
    filtersNotice.value = (error as Error).message
  } finally {
    savingFilters.value = false
  }
}
</script>

<style scoped lang="scss">
.hint {
  color: var(--color-text-maxcontrast);
  max-width: 60em;
  margin-bottom: 12px;
}

.field {
  max-width: 30em;
  margin-bottom: 4px;
}

.filter-table {
  width: 100%;
  max-width: 52em;
  margin-bottom: 20px;
  border-collapse: collapse;
  // Fixed, so a long mimetype list wraps inside its cell instead of stretching
  // the table past its max-width — auto layout sizes columns to their content.
  table-layout: fixed;

  th {
    padding: 4px 8px;
    color: var(--color-text-maxcontrast);
    font-weight: normal;
    text-align: start;
    border-bottom: 1px solid var(--color-border);
  }

  td {
    padding: 6px 8px;
    vertical-align: middle;
    // Nextcloud's settings stylesheet sets nowrap on table cells; without this
    // the mimetype list runs straight out of the table instead of wrapping.
    white-space: normal;
    border-bottom: 1px solid var(--color-border);
  }

  &__name {
    font-weight: 500;
  }

  &__mimetypes {
    color: var(--color-text-maxcontrast);
    font-size: 90%;
    overflow-wrap: anywhere;
  }

  &__origin {
    color: var(--color-text-maxcontrast);
    white-space: nowrap;
  }

  &__origin-column {
    width: 7em;
  }

  &__actions-column {
    width: 9em;
  }

  &__actions {
    display: flex;
    gap: 2px;
  }
}

.filter-list {
  &__hidden {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    margin-bottom: 20px;
  }

  &__save {
    margin-top: 12px;
  }
}

.muted {
  color: var(--color-text-maxcontrast);
}
</style>
