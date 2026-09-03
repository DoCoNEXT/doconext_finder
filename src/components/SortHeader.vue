<template>
  <button class="sort" :class="{ 'sort--active': active }" @click="$emit('sort', field)">
    <slot />
    <NcIconSvgWrapper v-if="active"
                      :path="descending ? mdiMenuDown : mdiMenuUp"
                      :size="18" />
  </button>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { NcIconSvgWrapper } from '@nextcloud/vue'
import { mdiMenuDown, mdiMenuUp } from '@mdi/js'

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

  // NcIconSvgWrapper's box takes the height of the row's line box and leaves
  // the 18px arrow sitting against its top edge — about 8px above the middle
  // of the label it belongs to. Pinning the box to the arrow's own size lets
  // the flex row centre it on the text.
  :deep(.icon-vue) {
    display: flex;
    align-items: center;
    flex: 0 0 auto;
    width: 18px;
    height: 18px;
  }

  &:hover {
    color: var(--color-main-text);
  }

  &--active {
    color: var(--color-main-text);
  }
}
</style>
