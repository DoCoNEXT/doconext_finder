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
        {{ t('Sort descending') }}
      </NcCheckboxRadioSwitch>
      <!--
        Named for the direction rather than for one field's version of it: the
        list above sorts on names and metadata too, where "newest or largest"
        says nothing.
      -->
      <p class="muted settings__hint">
        {{ t('Highest value first — newest for dates, largest for sizes, Z to A for names.') }}
      </p>
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
        {{ t('A single click selects a result; its details appear in the panel when the panel is open.') }}
      </p>
    </section>

    <!--
      Only where there is a preview to mark: without the Files Preview app a
      file is shown as a thumbnail, and a thumbnail has no words in it.
    -->
    <section v-if="richPreview" class="settings__section">
      <h4>{{ t('Preview') }}</h4>
      <p class="muted settings__hint">
        {{ t('The words you searched for are marked in the preview of a file. Pick the colour that stands out for you.') }}
      </p>

      <div class="settings__colour">
        <NcColorPicker :model-value="preferences.highlightColor || undefined"
                       advanced-fields
                       @submit="onHighlightColor">
          <NcButton variant="secondary">
            {{ t('Pick a colour') }}
          </NcButton>
        </NcColorPicker>

        <!-- The setting drawn as what it does, rather than as a swatch beside a word. -->
        <span class="settings__sample">
          {{ t('Terms are') }} <span class="settings__mark" :style="markStyle">{{ t('marked like this') }}</span>
        </span>

        <NcButton v-if="preferences.highlightColor"
                  variant="tertiary"
                  @click="preferences.setHighlightColor('')">
          {{ t('Use the default colour') }}
        </NcButton>
      </div>
    </section>

    <section class="settings__section">
      <h4>{{ t('Columns, grouping and the details panel') }}</h4>
      <p class="muted settings__hint">
        {{ t('These are set in the app itself, next to the results, where you can see their effect — and where the switch is beside the thing it switches.') }}
      </p>
      <NcButton variant="secondary" @click="confirmReset">
        {{ t('Reset everything to defaults') }}
      </NcButton>
    </section>
  </NcSettingsSection>
</template>

<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { NcButton, NcCheckboxRadioSwitch, NcColorPicker, NcSettingsSection } from '@nextcloud/vue'
import { useI18n } from '../composables/useI18n'
import { usePreferencesStore } from '../stores/preferencesStore'
import { useSearchStore } from '../stores/searchStore'
import { readableTextOn } from '../filters/colors'
import { HAS_RICH_PREVIEW, PRODUCT_NAME } from '../constants'

const { t } = useI18n()
const preferences = usePreferencesStore()
const search = useSearchStore()

onMounted(() => {
  preferences.load()
  // Needed for the metadata fields offered as sort options below.
  search.loadSchema()
})

const richPreview = HAS_RICH_PREVIEW

/** Mirrors the server's list; anything else is refused and falls back to 50. */
const PAGE_SIZES = [25, 50, 100, 200]

/**
 * What the mark looks like with nothing chosen. The one line this app repeats
 * from the renderer that draws it (doconext_files_preview, FilePreview.vue): a
 * sample that showed some other colour than the preview would be worse than no
 * sample at all, and there is no stylesheet of that app on a settings page to
 * read the real value from.
 */
const DEFAULT_MARK = {
  backgroundColor: 'color-mix(in srgb, var(--color-primary-element) 35%, var(--color-primary-element-light))',
  color: 'var(--color-primary-element-light-text)',
}

const markStyle = computed(() => {
  const colour = preferences.highlightColor

  return colour
    ? { backgroundColor: colour, color: readableTextOn(colour) }
    : DEFAULT_MARK
})

/**
 * Saved when the picker is confirmed rather than while a colour is being
 * dragged around: the picker emits on every movement, and each of those would
 * be a write.
 * @param colour the chosen colour, or undefined when it was cleared
 */
function onHighlightColor(colour: string | undefined) {
  preferences.setHighlightColor(colour ?? '')
}

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

.settings__colour {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
}

.settings__sample {
  color: var(--color-text-maxcontrast);
}

// Padded the way a marked word in a document is not — a mark there is the width
// of the word — but a chip needs a little air to read as a sample.
.settings__mark {
  padding: 1px 4px;
  border-radius: 2px;
  color: var(--color-main-text);
}

.muted {
  color: var(--color-text-maxcontrast);
}
</style>
