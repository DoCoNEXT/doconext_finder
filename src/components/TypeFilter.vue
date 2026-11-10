<template>
  <!--
    Several types at once are alternatives. The picked ones read as one line of
    text — "PDFs, Emails" — rather than as a chip each: the chips made this the
    one field in the grid taller than its neighbours, and the chips on the
    folded filter line already offer to take a single type back out. In the
    list, a picked type is ticked and clicking it again unpicks it; the list
    stays open while picking, so a second type is one click rather than two.
  -->
  <NcSelect v-model="selection"
            class="type-filter"
            :class="{ 'finder-filter--set': selection.length > 0 }"
            label="label"
            multiple
            keep-open
            deselect-from-dropdown
            :options="options"
            :placeholder="t('Any type')"
            :input-label="t('Type')">
    <template #selected-option-container="{ option }">
      <!--
        One line for all of them, drawn where the first one would go. The others
        still render an element: a slot holding nothing but a v-if comment counts
        as empty, and vue-select would draw its own chip there instead.
        `vs__selected` because the library lifts the label onto the border only
        when it finds one; its chip look is undone below.
      -->
      <span v-if="option.id === selection[0]?.id"
            class="vs__selected type-filter__summary"
            :title="summary">{{ summary }}</span>
      <span v-else hidden />
    </template>
    <template #option="option">
      <span class="type-filter__option">
        <Check class="type-filter__tick"
               :class="{ 'type-filter__tick--on': isPicked(option) }"
               :size="16" />
        {{ option.label }}
      </span>
    </template>
  </NcSelect>
</template>

<script setup lang="ts">
/**
 * The Type filter: which kinds of file a search returns, any of several.
 */
import { computed } from 'vue'
import { NcSelect } from '@nextcloud/vue'
import { Check } from '@lucide/vue'
import { useI18n } from '../composables/useI18n'
import type { FileTypePreset } from '../filters/presets'

defineProps<{
  /** Every type there is to pick. */
  options: FileTypePreset[]
}>()

const selection = defineModel<FileTypePreset[]>({ required: true })

const { t } = useI18n()

const summary = computed(() => selection.value.map((type) => type.label).join(', '))

/**
 * @param option an entry of the list
 */
function isPicked(option: FileTypePreset): boolean {
  return selection.value.some((type) => type.id === option.id)
}
</script>

<style scoped lang="scss">
/*
 * The multiple variant draws its border on the toggle, with padding and chips
 * of its own, where the single one lets its inner input field draw the box.
 * Brought back to the single one's 34px so the field lines up with Modified
 * beside it. Four classes, because the library's own rules are
 * `.nc-select.v-select.select …` and load after this app's CSS.
 */
.type-filter.nc-select.v-select.select {
  :deep(.vs__dropdown-toggle) {
    padding-block: 0;
  }

  // The toggle's own 2px border goes around this: 30 + 4 = the single one's 34.
  // Its own no-wrap, not the library's: that one scrolls the row to keep the
  // search input in view, which slid the start of the line out of sight.
  // `clip`, not `hidden`: a hidden overflow is still a scroll container, and
  // focusing the input when the list opens scrolled it all the same.
  :deep(.vs__selected-options) {
    flex-wrap: nowrap;
    align-items: center;
    height: 30px;
    min-width: 0;
    overflow: clip;
  }

  .type-filter__summary {
    display: block;
    flex: 0 1 auto;
    min-width: 0;
    min-height: 0;
    margin: 0;
    padding: 0 0 0 8px;
    overflow: hidden;
    border-radius: 0 !important;
    // The colour is left to filter-set.scss, which marks a filter in force.
    background: none;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
}

.type-filter__option {
  display: flex;
  align-items: center;
  gap: 8px;
}

/* Kept in place when unticked, so every label starts at the same x. */
.type-filter__tick {
  flex: none;
  visibility: hidden;

  &--on {
    visibility: visible;
  }
}
</style>

<style lang="scss">
/*
 * Hovering a ticked type is the library's "click to remove", drawn in the
 * error colour with white text — an alarm for unticking a box. The tick
 * already says what a click will do, so it gets the ordinary hover instead.
 *
 * Global because the list is teleported to <body>, out of reach of this
 * component's scope id. Only a select with deselect-from-dropdown ever uses
 * these two variables, and this is the one that has it. `body` in front to
 * outrank the library's own `.nc-select__dropdown.vs__dropdown-menu`, which
 * loads after this app's CSS.
 */
body .nc-select__dropdown.vs__dropdown-menu {
  --vs-dropdown-option--deselect-bg: var(--color-background-hover);
  --vs-dropdown-option--deselect-color: var(--color-main-text);
}
</style>
