<template>
  <div class="view-options">
    <label class="view-options__group">
      <span class="muted">{{ t('Group by:') }}</span>
      <select :value="preferences.grouping" @change="onGrouping">
        <option v-for="option in groupings" :key="option.value" :value="option.value">
          {{ option.label }}
        </option>
      </select>
    </label>

    <NcActions :aria-label="t('Columns')" :menu-name="t('Columns')">
      <template #icon>
        <NcIconSvgWrapper :path="mdiViewColumnOutline" :size="20" />
      </template>

      <NcActionCheckbox v-for="column in preferences.columns"
                        :key="column.id"
                        :model-value="column.visible"
                        :disabled="column.id === 'name'"
                        @update:model-value="preferences.toggle(column.id)">
        {{ headerOf(column) }}
      </NcActionCheckbox>

      <NcActionSeparator />

      <NcActionButton v-for="column in preferences.columns"
                      :key="`up-${column.id}`"
                      @click="preferences.move(column.id, -1)">
        <template #icon>
          <NcIconSvgWrapper :path="mdiArrowUp" :size="20" />
        </template>
        {{ t('Move up: {name}', { name: headerOf(column) }) }}
      </NcActionButton>

      <NcActionSeparator />

      <NcActionButton @click="renameColumn">
        <template #icon>
          <NcIconSvgWrapper :path="mdiPencilOutline" :size="20" />
        </template>
        {{ t('Rename a column…') }}
      </NcActionButton>
      <NcActionButton @click="preferences.reset()">
        <template #icon>
          <NcIconSvgWrapper :path="mdiRestore" :size="20" />
        </template>
        {{ t('Reset columns') }}
      </NcActionButton>
    </NcActions>

    <span v-if="hint" class="view-options__hint muted">{{ hint }}</span>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import {
  NcActionButton,
  NcActionCheckbox,
  NcActionSeparator,
  NcActions,
  NcIconSvgWrapper,
} from '@nextcloud/vue'
import { mdiArrowUp, mdiPencilOutline, mdiRestore, mdiViewColumnOutline } from '@mdi/js'
import { useI18n } from '../composables/useI18n'
import { usePreferencesStore } from '../stores/preferencesStore'
import type { ColumnPref } from '../types/Search'

const { t } = useI18n()
const preferences = usePreferencesStore()

const props = defineProps<{
  /** True when only part of the result set is loaded — grouping then misleads. */
  partial?: boolean
}>()

const groupings = computed(() => [
  { value: '', label: t('Nothing') },
  { value: 'folder', label: t('Folder') },
  { value: 'type', label: t('Type') },
  { value: 'modified', label: t('Modified date') },
])

/**
 * Grouping runs over the loaded rows, so on a partial result it describes the
 * page rather than the search. Say so rather than letting it read as a summary.
 */
const hint = computed(() =>
  preferences.grouping && props.partial
    ? t('Grouping covers the loaded results only — use Load all for the whole set.')
    : '')

function headerOf(column: ColumnPref): string {
  if (column.label) {
    return column.label
  }
  switch (column.id) {
    case 'name': return t('Name')
    case 'folder': return t('Folder')
    case 'size': return t('Size')
    case 'modified': return t('Modified')
    case 'created': return t('Created')
    case 'type': return t('Type')
    default: return column.id
  }
}

function onGrouping(event: Event) {
  preferences.setGrouping((event.target as HTMLSelectElement).value)
}

function renameColumn() {
  const names = preferences.columns.map((c) => headerOf(c)).join(', ')
  const which = window.prompt(t('Which column? ({names})', { names }), '')
  if (!which) {
    return
  }
  const column = preferences.columns.find((c) => headerOf(c).toLowerCase() === which.trim().toLowerCase())
  if (!column) {
    return
  }
  const label = window.prompt(t('New name (empty to restore the default)'), column.label)
  if (label === null) {
    return
  }
  preferences.rename(column.id, label)
}
</script>

<style scoped lang="scss">
.view-options {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 8px;

  &__group {
    display: flex;
    align-items: center;
    gap: 6px;
  }

  &__hint {
    font-size: 90%;
  }
}

.muted {
  color: var(--color-text-maxcontrast);
}
</style>
