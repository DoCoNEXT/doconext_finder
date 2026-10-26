<template>
  <!--
    A bare <button>, not NcButton: it is the column header itself, which has
    to look like the other headers and still be reachable by keyboard. Drawn
    the way Core's SortHeaderButton draws it, so a table looks the same in
    both apps: the arrow shows once the column sorts, or while the pointer is
    on it.
  -->
  <button type="button"
          class="sort"
          :class="{ 'sort--active': active }"
          :title="title"
          @click="$emit('sort', field)">
    <slot />
    <span class="sort__indicator" aria-hidden="true">{{ active ? (descending ? '▼' : '▲') : '⇅' }}</span>
  </button>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from '../composables/useI18n'

const { t } = useI18n()

const props = defineProps<{
  /** Column this header sorts by, as named by the backend's `sorts` list. */
  field: string
  sort: string
  descending: boolean
  /** Which way a first click sorts this column. */
  startsDescending: boolean
}>()

defineEmits<{ (e: 'sort', field: string): void }>()

const active = computed(() => props.sort === props.field)

// What a click will do.
const title = computed(() => {
  if (!active.value) {
    return props.startsDescending ? t('Click to sort descending') : t('Click to sort ascending')
  }
  return props.descending
    ? t('Sorted descending — click to sort ascending')
    : t('Sorted ascending — click to sort descending')
})
</script>

<style scoped lang="scss">
.sort {
  display: inline-flex;
  align-items: center;
  padding: 0;
  border: none;
  background: none;
  font: inherit;
  color: inherit;
  cursor: pointer;
  user-select: none;

  &:hover,
  &--active {
    color: var(--color-main-text);
  }

  // Nextcloud paints every plain button's hover and focus with a tinted
  // background, at a weight a bare class does not reach; a header shows no
  // box. Its focus ring, which core also draws, is left as it is.
  &:hover,
  &:focus {
    background-color: transparent;
  }

  &__indicator {
    margin-inline-start: 4px;
    font-size: 11px;
    line-height: 1;
    opacity: 0;
  }

  &:hover &__indicator {
    opacity: 0.6;
  }

  &--active &__indicator {
    opacity: 1;
    color: var(--color-primary-element);
  }
}
</style>
