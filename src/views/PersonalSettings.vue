<template>
  <NcSettingsSection :name="PRODUCT_NAME" :description="t('These apply to you only, and take effect on your next search.')">
    <section class="settings__section">
      <h4>{{ t('Results') }}</h4>

      <label class="settings__row">
        <span>{{ t('Results per page') }}</span>
        <select :value="preferences.pageSize" @change="onPageSize">
          <option v-for="size in PAGE_SIZES" :key="size" :value="size">{{ size }}</option>
        </select>
      </label>

      <label class="settings__row">
        <span>{{ t('Sort new searches by') }}</span>
        <select :value="preferences.sort" @change="onSort">
          <option v-for="option in sortOptions" :key="option.value" :value="option.value">
            {{ option.label }}
          </option>
        </select>
      </label>

      <NcCheckboxRadioSwitch :model-value="preferences.descending"
                             @update:model-value="preferences.setDefaultSort(preferences.sort, $event)">
        {{ t('Newest or largest first') }}
      </NcCheckboxRadioSwitch>
    </section>

    <section class="settings__section">
      <h4>{{ t('Behaviour') }}</h4>

      <label class="settings__row">
        <span>{{ t('Double-clicking a result') }}</span>
        <select :value="preferences.doubleClick" @change="onDoubleClick">
          <option value="open">{{ t('Opens the file') }}</option>
          <option value="folder">{{ t('Opens its folder') }}</option>
          <option value="none">{{ t('Does nothing') }}</option>
        </select>
      </label>
      <p class="muted settings__hint">
        {{ t('A single click always selects the result and shows its details.') }}
      </p>

      <NcCheckboxRadioSwitch :model-value="preferences.sidebarPinned"
                             @update:model-value="preferences.setSidebarPinned($event)">
        {{ t('Keep the details panel open') }}
      </NcCheckboxRadioSwitch>
      <p class="muted settings__hint">
        {{ t('The panel follows your selection instead of closing between searches.') }}
      </p>
    </section>

    <section class="settings__section">
      <h4>{{ t('Columns and grouping') }}</h4>
      <p class="muted settings__hint">
        {{ t('Columns and grouping are set in the app itself, next to the results, where you can see their effect.') }}
      </p>
      <NcButton @click="confirmReset">
        {{ t('Reset everything to defaults') }}
      </NcButton>
    </section>
  </NcSettingsSection>
</template>

<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { NcButton, NcCheckboxRadioSwitch, NcSettingsSection } from '@nextcloud/vue'
import { useI18n } from '../composables/useI18n'
import { usePreferencesStore } from '../stores/preferencesStore'
import { useSearchStore } from '../stores/searchStore'
import { PRODUCT_NAME } from '../constants'

const { t } = useI18n()
const preferences = usePreferencesStore()
const search = useSearchStore()

onMounted(() => {
  preferences.load()
  // Needed for the metadata fields offered as sort options below.
  search.loadSchema()
})

/** Mirrors the server's list; anything else is refused and falls back to 50. */
const PAGE_SIZES = [25, 50, 100, 200]

/**
 * Sorting is server-side, so only indexed metadata can be offered here — the
 * rest has no column to order by.
 */
const sortOptions = computed(() => [
  { value: 'mtime', label: t('Modified') },
  { value: 'name', label: t('Name') },
  { value: 'size', label: t('Size') },
  { value: 'creation_time', label: t('Created') },
  ...(search.schema?.metadata ?? [])
    .filter((field) => field.filterable)
    .map((field) => ({ value: field.field, label: field.label })),
])

function onPageSize(event: Event) {
  preferences.setPageSize(Number((event.target as HTMLSelectElement).value))
}

function onSort(event: Event) {
  preferences.setDefaultSort((event.target as HTMLSelectElement).value, preferences.descending)
}

function onDoubleClick(event: Event) {
  preferences.setDoubleClick((event.target as HTMLSelectElement).value)
}

/**
 * Reset drops a column layout that may have taken a while to arrange, so it
 * asks first.
 */
function confirmReset() {
  if (window.confirm(t('Reset all Finder settings, columns and grouping to their defaults?'))) {
    preferences.reset()
  }
}
</script>

<style scoped lang="scss">
.settings__section {
  margin-top: 20px;

  &:first-child {
    margin-top: 0;
  }

  h4 {
    margin: 0 0 8px;
    font-weight: 600;
  }
}

.settings__row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  max-width: 420px;
  margin-bottom: 10px;
}

.settings__hint {
  margin: 4px 0 12px;
  font-size: 90%;
}

.muted {
  color: var(--color-text-maxcontrast);
}
</style>
