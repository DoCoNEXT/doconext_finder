<template>
  <div ref="root" class="search-box">
    <NcTextField
      :model-value="modelValue"
      :label="t('Search files')"
      :label-outside="true"
      :placeholder="t('Search by name, or pick a saved search…')"
      role="combobox"
      :aria-expanded="open"
      aria-autocomplete="list"
      @update:model-value="onInput"
      @focus="open = true"
      @keydown="onKeydown">
      <template #icon>
        <Search :size="20" />
      </template>
    </NcTextField>

    <!--
      Suggestions from the searches you already have, so a saved search is one
      keystroke away instead of a detour to another page. Kept in the box rather
      than in a component of its own: it is a control, not a view.
    -->
    <ul v-if="open && matches.length" class="search-box__list" role="listbox">
      <li v-for="(entry, index) in matches"
          :key="`${entry.kind}-${entry.id}`"
          role="option"
          :aria-selected="index === highlighted"
          :class="['search-box__item', { 'search-box__item--active': index === highlighted }]"
          @mousedown.prevent="choose(entry)"
          @mousemove="highlighted = index">
        <component :is="entry.kind === 'saved' ? Save : RotateCcwClock" :size="18" />
        <span class="search-box__label">
          <strong>{{ titleOf(entry) }}</strong>
          <span class="search-box__hint"
                :title="describeQuery(t, entry.query)">{{ describeQuery(t, entry.query) || t('No filters') }}</span>
        </span>
      </li>
    </ul>
  </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { NcTextField } from '@nextcloud/vue'
import { RotateCcwClock, Save, Search } from '@lucide/vue'
import { useI18n } from '../composables/useI18n'
import { useHistoryStore } from '../stores/historyStore'
import { describeQuery } from '../filters/describe'
import type { StoredSearch } from '../types/Search'

/** Enough to choose from without turning the box into a page of its own. */
const MAX_SUGGESTIONS = 8

const { t } = useI18n()
const history = useHistoryStore()

const props = defineProps<{ modelValue: string }>()

const emit = defineEmits<{
  (e: 'update:modelValue', value: string): void
  /** Enter with nothing highlighted: run what is in the box. */
  (e: 'search'): void
  (e: 'pick', entry: StoredSearch): void
}>()

const root = ref<HTMLElement>()
const open = ref(false)
const highlighted = ref(-1)

onMounted(() => {
  history.load()
  document.addEventListener('click', onDocumentClick)
})

onBeforeUnmount(() => document.removeEventListener('click', onDocumentClick))

/**
 * A click anywhere else closes the list; the field keeps its own focus rules.
 * @param event the click
 */
function onDocumentClick(event: MouseEvent) {
  if (!root.value?.contains(event.target as Node)) {
    open.value = false
  }
}

function titleOf(entry: StoredSearch): string {
  return entry.name || entry.query.term || t('(no search term)')
}

/**
 * Matches on both what the search is called and what it searches for: people
 * remember either, and a saved search often has no term at all.
 */
const matches = computed(() => {
  const needle = props.modelValue.trim().toLowerCase()
  const all = history.suggestions

  const hits = needle === ''
    ? all
    : all.filter((entry) =>
      `${entry.name ?? ''} ${entry.query.term ?? ''}`.toLowerCase().includes(needle))

  return hits.slice(0, MAX_SUGGESTIONS)
})

/**
 * @param value what the field now holds — NcTextField types it loosely, so it
 *   is narrowed here rather than at every call site
 */
function onInput(value: string | number) {
  emit('update:modelValue', String(value))
  open.value = true
  highlighted.value = -1
}

/**
 * @param entry the suggestion that was chosen
 */
function choose(entry: StoredSearch) {
  open.value = false
  highlighted.value = -1
  emit('pick', entry)
}

/**
 * Arrow keys walk the suggestions, Enter takes the highlighted one — or runs
 * what is typed when nothing is highlighted.
 * @param event the key press
 */
function onKeydown(event: KeyboardEvent) {
  switch (event.key) {
    case 'ArrowDown':
    case 'ArrowUp': {
      if (matches.value.length === 0) {
        return
      }
      event.preventDefault()
      open.value = true
      const step = event.key === 'ArrowDown' ? 1 : -1
      const count = matches.value.length
      highlighted.value = (highlighted.value + step + count + 1) % (count + 1) - 1
      break
    }
    case 'Enter': {
      const entry = matches.value[highlighted.value]
      if (open.value && entry) {
        event.preventDefault()
        choose(entry)
        return
      }
      open.value = false
      emit('search')
      break
    }
    case 'Escape':
      open.value = false
      highlighted.value = -1
      break
  }
}
</script>

<style scoped lang="scss">
.search-box {
  position: relative;

  &__list {
    position: absolute;
    // Above NcSelect's chevron overlay: since @nextcloud/vue 9.10 .vs__actions
    // is absolutely positioned with z-index 999, and neither it nor this list
    // sits in a stacking context of its own, so the arrows of the filter row
    // below would otherwise paint straight through the open suggestion list.
    z-index: 1000;
    inset-inline: 0;
    top: calc(100% + 4px);
    margin: 0;
    padding: 4px;
    list-style: none;
    max-height: 320px;
    overflow-y: auto;
    background: var(--color-main-background);
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large);
    box-shadow: 0 2px 12px var(--color-box-shadow, rgba(0, 0, 0, 0.2));
  }

  &__item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 8px;
    border-radius: var(--border-radius);
    cursor: pointer;

    &--active {
      background: var(--color-background-hover);
    }
  }

  &__label {
    display: flex;
    flex-direction: column;
    min-width: 0;
  }

  // Same reasoning as the Searches list: this line names every filter in the
  // search, so clipping it at one line hides all but the first.
  &__hint {
    color: var(--color-text-maxcontrast);
    font-size: 90%;
    overflow: hidden;
    text-overflow: ellipsis;
    display: -webkit-box;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 2;
    line-clamp: 2;
  }
}
</style>
