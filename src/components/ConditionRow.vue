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

    <!--
      A field that names people holds a bare id — `alice` — which nobody types
      from memory. The box searches on names and puts the id in the condition.
      filterable=false hands the narrowing to the server, which already did it:
      vue-select would otherwise filter a list it only partly has.
    -->
    <NcSelect v-else-if="input === 'principal'"
              v-model="principalValue"
              class="condition__value condition__person"
              label="displayName"
              :options="principalOptions"
              :filterable="false"
              :loading="searchingPrincipals"
              :placeholder="t('Type a name')"
              @search="onPrincipalSearch" />

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
        <X :size="20" />
      </template>
    </NcButton>
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import {
  NcButton,
  NcCheckboxRadioSwitch,
  NcDateTimePicker,
  NcSelect,
  NcTextField,
} from '@nextcloud/vue'
import { X } from '@lucide/vue'
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
import type { InputKind } from '../filters/fields'
import { isMetadataField, metadataKeyOf } from '../filters/metadata'
import { SearchApi } from '../services/SearchApi'
import type { Condition, FieldsResponse, Operator, Principal } from '../types/Search'

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

function metadataLabelOf(id: string) {
  return props.schema.metadata.find((f) => f.field === id)?.label ?? metadataKeyOf(id)
}

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
  if (!isMetadataField(field)) {
    return props.schema.operators[field] ?? []
  }

  // The picker hands over a whole id, so "contains" could only ever match one
  // by accident — `ann` sitting inside `joanne`.
  return principalOf(field) ? ['eq'] : props.schema.metadataOperators
}

/**
 * What a metadata field holds where it names people — which kind, and whether
 * it may name several — and null everywhere else. The server says so; nothing
 * about a stored `alice` reveals it.
 * @param field
 */
function principalOf(field: string) {
  return props.schema.metadata.find((f) => f.field === field)?.principal ?? null
}

// Metadata is stored as one indexed string column whatever its declared type,
// so it edits as text — except where it names people, who are picked rather
// than typed.
const input = computed<InputKind>(() => {
  if (!isMetadataField(props.condition.field)) {
    return fieldInput(props.condition.field)
  }

  return principalOf(props.condition.field) ? 'principal' : 'text'
})
const hint = computed(() => fieldHint(t, props.condition.field))

/**
 * "favorite" and "tagname" are reached through a join, so negating them compares
 * a NULL column and matches nothing. The backend refuses it; don't offer it.
 */
const canNegate = computed(() =>
  !['favorite', 'tagname'].includes(props.condition.field))

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
    patch({
      field: next.id,
      operator,
      value: next.id === 'favorite' ? true : '',
      negate: false,
      label: undefined,
    })
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

const principalOptions = ref<Principal[]>([])
const searchingPrincipals = ref(false)

const principalValue = computed({
  get: (): Principal | null => {
    const id = String(props.condition.value ?? '')
    if (id === '') {
      return null
    }

    // A stored search carries the name it was picked under; without one the id
    // is all there is, and showing it beats showing nothing.
    return principalOptions.value.find((person) => person.id === id)
      ?? { type: '', id, displayName: props.condition.label ?? id }
  },
  set: (next: Principal | null) => patch({ value: next?.id ?? '', label: next?.displayName }),
})

let principalDebounce: ReturnType<typeof setTimeout> | undefined

/**
 * @param term what has been typed into the person field
 * @param loading vue-select's own spinner toggle
 */
function onPrincipalSearch(term: string, loading: (state: boolean) => void) {
  clearTimeout(principalDebounce)
  if (term.trim() === '') {
    principalOptions.value = []
    return
  }

  // A keystroke is not a question; the same beat the entity picker waits.
  principalDebounce = setTimeout(async () => {
    searchingPrincipals.value = true
    loading(true)
    try {
      principalOptions.value = await SearchApi.principals(props.condition.field, term)
    } catch {
      principalOptions.value = []
    } finally {
      searchingPrincipals.value = false
      loading(false)
    }
  }, 250)
}

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
  // The two dropdowns carry a label above them and the value box does not, so
  // centring the row would hang the boxes at different heights. They line up on
  // their bottom edge instead — the line the eye follows across the row.
  align-items: end;
  gap: 8px;
  margin-bottom: 8px;

  // NcSelect reserves a gap under itself for a row of its own; here the row is
  // the alignment line, so the gap would lift the dropdowns off it and leave
  // every box beside them hanging 4px low. Their width is the library's own,
  // which is what puts them under the Type and Modified dropdowns of the row
  // above.

  // `.nc-select` is there for weight, not meaning. The library sets this margin
  // as `.nc-select.v-select.select`, three classes, which is all a nested scoped
  // rule reaches too, and its chunk loads after ours, so a tie went to it.
  .condition__field.nc-select,
  .condition__operator.nc-select,
  .condition__person.nc-select {
    margin-bottom: 0;
  }

  &__value {
    flex: 1 1 auto;
    min-width: 140px;

    // Words standing in for a box, so as tall as one: they sit on the middle
    // line of the dropdowns beside them instead of at the foot of the row.
    &--fixed {
      display: flex;
      align-items: center;
      min-height: var(--default-clickable-area);
      color: var(--color-text-maxcontrast);
    }
  }

  &__negate {
    flex: 0 0 auto;
  }
}
</style>
