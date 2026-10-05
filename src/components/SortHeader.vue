<template>
  <!--
    A bare <button>, not NcButton: it is the column header itself, which has
    to look like the other headers and still be reachable by keyboard.
  -->
  <button class="sort" :class="{ 'sort--active': active }" @click="$emit('sort', field)">
    <slot />
    <component :is="descending ? ChevronDown : ChevronUp"
               v-if="active"
               :size="18" />
  </button>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { ChevronDown, ChevronUp } from '@lucide/vue'

const props = defineProps<{
  /** Column this header sorts by, as named by the backend's `sorts` list. */
  field: string
  sort: string
  descending: boolean
}>()

defineEmits<{ (e: 'sort', field: string): void }>()

const active = computed(() => props.sort === props.field)
</script>

<style scoped lang="scss">
.sort {
  display: inline-flex;
  align-items: center;
  gap: 2px;
  background: none;
  border: none;
  padding: 0;
  font: inherit;
  color: inherit;
  cursor: pointer;

  &:hover {
    color: var(--color-main-text);
  }

  &--active {
    color: var(--color-main-text);
  }
}
</style>
