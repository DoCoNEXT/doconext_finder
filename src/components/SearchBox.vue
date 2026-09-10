<template>
  <div ref="root" class="search-box">
    <div class="search-box__frame">
      <div class="search-box__field">
        <NcTextField
          :model-value="modelValue"
          :label="t('Search files')"
          :label-outside="true"
          :placeholder="boxPlaceholder"
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
          Overlaid on the field's trailing padding rather than placed beside it,
          the way NcSelect hangs its chevron: it reads on the words in the box,
          so it belongs in the box. The field reserves the room through
          --input-padding-end, so the text never runs underneath.
        -->
        <NcButton v-if="understand"
                  variant="tertiary"
                  class="search-box__understand"
                  :disabled="understandDisabled"
                  :aria-label="t('Turn your question into filters')"
                  :title="t('Turn your question into filters')"
                  @click="emit('understand')">
          <template #icon>
            <NcLoadingIcon v-if="understanding" :size="20" />
            <Sparkles v-else :size="20" />
          </template>
        </NcButton>
      </div>
    </div>

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
import { NcButton, NcLoadingIcon, NcTextField } from '@nextcloud/vue'
import { RotateCcwClock, Save, Search, Sparkles } from '@lucide/vue'
import { useI18n } from '../composables/useI18n'
import { useHistoryStore } from '../stores/historyStore'
import { describeQuery } from '../filters/describe'
import type { StoredSearch } from '../types/Search'

/** Enough to choose from without turning the box into a page of its own. */
const MAX_SUGGESTIONS = 8

const { t } = useI18n()
const history = useHistoryStore()

const props = withDefaults(defineProps<{
  modelValue: string
  /** What the box says it will do; the caller decides, since it knows the target. */
  placeholder?: string
  /** Whether DoCoNEXT Core is here to read a question. */
  understand?: boolean
  understandDisabled?: boolean
  understanding?: boolean
}>(), {
  placeholder: '',
  understand: false,
  understandDisabled: false,
  understanding: false,
})

const emit = defineEmits<{
  (e: 'update:modelValue', value: string): void
  /** Enter with nothing highlighted: run what is in the box. */
  (e: 'search'): void
  (e: 'pick', entry: StoredSearch): void
  /** Hand what is typed to the distiller. */
  (e: 'understand'): void
}>()

/** The caller's wording, or the plain one when it did not say. */
const boxPlaceholder = computed(() => props.placeholder || t('Search by name, or pick a recent or saved search…'))

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

  &__frame {
    display: flex;
    align-items: center;
    // No gap: the segment and the field are one control, and a gap would make
    // them read as two that happen to sit near each other.
    gap: 0;
  }

  &__field {
    position: relative;
    flex: 1 1 auto;
    min-width: 0;

    // Room for the two overlays, the same way NcSelect reserves it for its
    // chevron. Set on the component's own root rather than inherited from here:
    // NcInputField redefines these properties on `.input-field` itself, so a
    // value handed down from an ancestor never reaches the input.
    :deep(.input-field) {
      --input-padding-end: calc(var(--default-clickable-area) + var(--default-grid-baseline));
    }

    // Nested rather than a bare `&__understand`, and deliberately: NcButton
    // sets `position: relative` on `.button-vue` at the same specificity a
    // single scoped class reaches, so source order decided it and the button
    // stayed in the flow — sitting under the field and doubling its height.
    // The child combinator outranks it instead of fighting over load order.
    > .search-box__understand {
      position: absolute;
      inset-inline-end: var(--default-grid-baseline);
      // Not translateY(-50%): NcButton sets its own transform while pressed,
      // which replaced the centring one and made the button jump down half its
      // height on every click. It is exactly as tall as the field, so nothing
      // needs centring anyway.
      top: 0;
      // Above the input's own background, which would otherwise paint over it.
      z-index: 1;
    }
  }

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

    // Lucide renders a bare <svg>, and a flex item shrinks in proportion to
    // its base size, so the icon gives up its share of the overflow whenever
    // the description next to it is long — min-width: 0 on the label does not
    // spare it. Without this, a two-line suggestion gets a visibly smaller
    // icon than its neighbours.
    > :deep(svg) {
      flex-shrink: 0;
    }

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
