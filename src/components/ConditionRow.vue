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
      "Created by" compares the account id, which nobody knows by heart. The box
      searches on the name and puts the id in the condition. filterable=false
      hands the narrowing to the server, which already did it — vue-select would
      otherwise filter a list it only partly has.
    -->
    <NcSelect v-else-if="input === 'user'"
              v-model="userValue"
              class="condition__value condition__person"
              label="displayName"
              :options="userOptions"
              :filterable="false"
              :loading="searchingUsers"
              :placeholder="t('Type a name')"
              @search="onUserSearch" />

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
import { computed, ref, watch } from 'vue'
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
import { isMetadataField, metadataKeyOf } from '../filters/metadata'
import { SearchApi } from '../services/SearchApi'
import type { Condition, FieldsResponse, Operator, Person } from '../types/Search'

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

const userOptions = ref<Person[]>([])
const searchingUsers = ref(false)

/**
 * uid → display name, for a value that arrived from a stored search rather than
 * from the picker: the condition carries the id, and showing that raw would
 * make a restored filter unreadable.
 */
const names = ref<Record<string, string>>({})

watch(
  () => [props.condition.field, props.condition.value] as const,
  async ([field, value]) => {
    const uid = String(value ?? '')
    // A stored search carries the name with it; only a bare id needs asking.
    if (field !== 'owner' || uid === '' || props.condition.label || names.value[uid] !== undefined) {
      return
    }
    names.value = { ...names.value, [uid]: await SearchApi.userName(uid) }
  },
  { immediate: true },
)

const userValue = computed({
  get: (): Person | null => {
    const uid = String(props.condition.value ?? '')
    if (uid === '') {
      return null
    }

    return userOptions.value.find((person) => person.uid === uid)
      ?? { uid, displayName: props.condition.label ?? names.value[uid] ?? uid }
  },
  set: (next: Person | null) => patch({ value: next?.uid ?? '', label: next?.displayName }),
})

let userDebounce: ReturnType<typeof setTimeout> | undefined

/**
 * @param term what has been typed into the person field
 * @param loading vue-select's own spinner toggle
 */
function onUserSearch(term: string, loading: (state: boolean) => void) {
  clearTimeout(userDebounce)
  if (term.trim() === '') {
    userOptions.value = []
    return
  }

  // A keystroke is not a question; the same beat the entity picker waits.
  userDebounce = setTimeout(async () => {
    searchingUsers.value = true
    loading(true)
    try {
      userOptions.value = await SearchApi.users(term)
    } catch {
      userOptions.value = []
    } finally {
      searchingUsers.value = false
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
  // the alignment line, so the gap would lift the dropdowns off it. Their width
  // is the library's own, which is what puts them under the Type and Modified
  // dropdowns of the row above. The person picker is an NcSelect too, and
  // reserved the same gap — which left it hanging above the box beside it.
  .condition__field,
  .condition__operator,
  .condition__person {
    margin-bottom: 0;
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
