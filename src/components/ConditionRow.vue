<template>
  <div class="condition">
    <NcSelect v-model="fieldOption"
              class="condition__field"
              label="label"
              :options="fieldOptions"
              :clearable="false"
              :input-label="t('Field')" />

    <NcSelect v-model="operatorOption"
              class="condition__operator"
              label="label"
              :options="operatorOptions"
              :clearable="false"
              :input-label="t('Condition')" />

    <NcDateTimePicker v-if="input === 'date'"
                      v-model="dateValue"
                      class="condition__value"
                      type="date"
                      :placeholder="t('Pick a date')" />

    <NcTextField v-else-if="input === 'size'"
                 v-model="sizeValue"
                 class="condition__value"
                 type="number"
                 :label="t('Size in MB')"
                 :label-outside="true"
                 :placeholder="t('Size in MB')" />

    <NcTextField v-else-if="input === 'text'"
                 v-model="textValue"
                 class="condition__value"
                 :label="t('Value')"
                 :label-outside="true"
                 :placeholder="hint || t('Value')" />

    <span v-else class="condition__value condition__value--fixed">
      {{ t('Only favorited files') }}
    </span>

    <NcCheckboxRadioSwitch v-if="canNegate"
                           :model-value="condition.negate ?? false"
                           class="condition__negate"
                           @update:model-value="patch({ negate: $event })">
      {{ t('not') }}
    </NcCheckboxRadioSwitch>

    <NcButton :aria-label="t('Remove condition')"
              variant="tertiary"
              @click="$emit('remove')">
      <template #icon>
        <NcIconSvgWrapper :path="mdiClose" :size="20" />
      </template>
    </NcButton>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import {
  NcButton,
  NcCheckboxRadioSwitch,
  NcDateTimePicker,
  NcIconSvgWrapper,
  NcSelect,
  NcTextField,
} from '@nextcloud/vue'
import { mdiClose } from '@mdi/js'
import { useI18n } from '../composables/useI18n'
import {
  fieldHint,
  fieldInput,
  fieldLabel,
  operatorLabel,
  toBytes,
  toDateInput,
  toMegabytes,
  toUnix,
} from '../filters/fields'
import { isMetadataField, metadataKeyOf } from '../filters/metadata'
import type { Condition, FieldsResponse, Operator } from '../types/Search'

const { t } = useI18n()

const props = defineProps<{
  condition: Condition
  schema: FieldsResponse
}>()

const emit = defineEmits<{
  (e: 'update:condition', value: Condition): void
  (e: 'remove'): void
}>()

interface Option { id: string, label: string }

const metadataLabelOf = (id: string) =>
  props.schema.metadata.find((f) => f.field === id)?.label ?? metadataKeyOf(id)

const labelFor = (id: string) => (isMetadataField(id) ? metadataLabelOf(id) : fieldLabel(t, id))

const fieldOptions = computed<Option[]>(() => [
  ...Object.keys(props.schema.fields).map((id) => ({ id, label: fieldLabel(t, id) })),
  // Only indexed metadata can be compared against; the rest is display-only.
  ...props.schema.metadata
    .filter((field) => field.filterable)
    .map((field) => ({ id: field.field, label: field.label })),
])

/** Only the operators this field accepts — the server rejects the rest. */
const operatorOptions = computed<Option[]>(() =>
  operatorsFor(props.condition.field)
    .map((id) => ({ id, label: operatorLabel(t, props.condition.field, id) })))

/**
 * Metadata accepts its own small set; a file column has its own whitelist.
 * @param field
 */
function operatorsFor(field: string): Operator[] {
  return isMetadataField(field)
    ? props.schema.metadataOperators
    : props.schema.operators[field] ?? []
}

// Metadata is stored as one indexed string column whatever its declared type,
// so it always edits as text.
const input = computed(() =>
  (isMetadataField(props.condition.field) ? 'text' : fieldInput(props.condition.field)))
const hint = computed(() => fieldHint(t, props.condition.field))

/**
 * "favorite", "tagname" and "owner" are reached through a join, so negating them
 * compares a NULL column and matches nothing. The backend refuses it; don't
 * offer it.
 */
const canNegate = computed(() =>
  !['favorite', 'tagname', 'owner'].includes(props.condition.field))

function patch(changes: Partial<Condition>) {
  emit('update:condition', { ...props.condition, ...changes })
}

const fieldOption = computed({
  get: () => ({ id: props.condition.field, label: labelFor(props.condition.field) }),
  set: (next: Option | null) => {
    if (!next) {
      return
    }
    // The new field may not accept the current operator, and its value is in a
    // different unit — reset both rather than carry a nonsense pairing across.
    const operators = operatorsFor(next.id)
    const operator = (operators.includes(props.condition.operator)
      ? props.condition.operator
      : operators[0]) as Operator
    patch({ field: next.id, operator, value: next.id === 'favorite' ? true : '', negate: false })
  },
})

const operatorOption = computed({
  get: () => ({
    id: props.condition.operator,
    label: operatorLabel(t, props.condition.field, props.condition.operator),
  }),
  set: (next: Option | null) => next && patch({ operator: next.id as Operator }),
})

const textValue = computed({
  get: () => String(props.condition.value ?? ''),
  set: (next: string) => patch({ value: next }),
})

// The condition carries wire units (Unix seconds, bytes); the boxes show
// dates and megabytes. Convert at the edge so the request needs no fixing up.
const sizeValue = computed({
  get: () => toMegabytes(props.condition.value as number),
  set: (next: string) => patch({ value: toBytes(next) }),
})

const dateValue = computed({
  get: () => {
    const iso = toDateInput(props.condition.value as number)
    return iso ? new Date(`${iso}T00:00:00`) : null
  },
  set: (next: Date | null) => {
    if (!next) {
      patch({ value: '' })
      return
    }
    const pad = (n: number) => String(n).padStart(2, '0')
    const iso = `${next.getFullYear()}-${pad(next.getMonth() + 1)}-${pad(next.getDate())}`
    patch({ value: toUnix(iso) })
  },
})
</script>

<style scoped lang="scss">
.condition {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 8px;

  &__field,
  &__operator {
    min-width: 170px;
  }

  &__value {
    flex: 1 1 auto;
    min-width: 140px;

    &--fixed {
      color: var(--color-text-maxcontrast);
    }
  }

  &__negate {
    flex: 0 0 auto;
  }
}
</style>
