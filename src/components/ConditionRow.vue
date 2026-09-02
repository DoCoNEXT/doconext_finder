<template>
  <div class="condition">
    <NcSelect v-model="field"
              class="condition__field"
              :options="fieldNames"
              :clearable="false"
              :aria-label-combobox="t('Field')" />

    <NcSelect v-model="operator"
              class="condition__operator"
              :options="allowedOperators"
              :clearable="false"
              :aria-label-combobox="t('Operator')" />

    <component :is="valueComponent"
               v-if="valueKind !== 'boolean'"
               v-model="value"
               class="condition__value"
               :type="valueKind === 'integer' ? 'number' : 'text'"
               :label="valueLabel"
               :label-outside="true"
               :placeholder="valueLabel" />
    <span v-else class="condition__value condition__value--fixed">
      {{ t('is favorited') }}
    </span>

    <NcCheckboxRadioSwitch v-if="canNegate"
                           :model-value="negate"
                           class="condition__negate"
                           @update:model-value="$emit('update:negate', $event)">
      {{ t('not') }}
    </NcCheckboxRadioSwitch>

    <NcButton :aria-label="t('Remove condition')"
              variant="tertiary"
              @click="$emit('remove')">
      ✕
    </NcButton>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { NcButton, NcCheckboxRadioSwitch, NcSelect, NcTextField } from '@nextcloud/vue'
import { useI18n } from '../composables/useI18n'
import type { Condition, FieldsResponse, Operator } from '../types/Search'

const { t } = useI18n()

const props = defineProps<{
  condition: Condition
  schema: FieldsResponse
}>()

const emit = defineEmits<{
  (e: 'update:condition', value: Condition): void
  (e: 'update:negate', value: boolean): void
  (e: 'remove'): void
}>()

const valueComponent = NcTextField

const fieldNames = computed(() => Object.keys(props.schema.fields))

/** Only the operators this field accepts — the server rejects the rest. */
const allowedOperators = computed<Operator[]>(
  () => props.schema.operators[props.condition.field] ?? [],
)

const valueKind = computed(() => props.schema.fields[props.condition.field] ?? 'string')

/**
 * "favorite" is join-backed: the backend can only match favorited files, and
 * negating it matches nothing, so the toggle is hidden for it.
 */
const canNegate = computed(() => !['favorite', 'tagname'].includes(props.condition.field))

const valueLabel = computed(() => {
  switch (valueKind.value) {
    case 'integer':
      return isDate.value ? t('Unix seconds') : t('Value')
    default:
      return t('Value')
  }
})

const isDate = computed(() => ['mtime', 'creation_time'].includes(props.condition.field))

function patch(changes: Partial<Condition>) {
  emit('update:condition', { ...props.condition, ...changes })
}

const field = computed({
  get: () => props.condition.field,
  set: (next: string) => {
    // The new field may not accept the current operator; fall back to its first.
    const operators = props.schema.operators[next] ?? []
    const operator = operators.includes(props.condition.operator)
      ? props.condition.operator
      : (operators[0] ?? props.condition.operator)
    patch({ field: next, operator, negate: false })
  },
})

const operator = computed({
  get: () => props.condition.operator,
  set: (next: Operator) => patch({ operator: next }),
})

const value = computed({
  get: () => String(props.condition.value ?? ''),
  set: (next: string) => patch({ value: next }),
})

const negate = computed(() => props.condition.negate ?? false)
</script>

<style scoped lang="scss">
.condition {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 8px;

	&__field,
	&__operator {
		min-width: 150px;
	}

	&__value {
		flex: 1 1 auto;
		min-width: 120px;

		&--fixed {
			color: var(--color-text-maxcontrast);
		}
	}

	&__negate {
		flex: 0 0 auto;
	}
}
</style>
