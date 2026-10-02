<template>
  <div class="view-options">
    <!-- Grouping is a stack of levels, applied outermost first, like the desktop app. -->
    <span class="muted">{{ t('Group by:') }}</span>

    <div v-if="levels.length" class="view-options__levels">
      <span v-for="(level, index) in levels" :key="level" class="chip">
        <span class="chip__label">{{ levelLabel(level) }}</span>
        <button v-if="index > 0"
                class="chip__button"
                :aria-label="t('Move outward')"
                @click="preferences.moveGrouping(scope, level, -1)">‹</button>
        <button v-if="index < levels.length - 1"
                class="chip__button"
                :aria-label="t('Move inward')"
                @click="preferences.moveGrouping(scope, level, 1)">›</button>
        <button class="chip__button"
                :aria-label="t('Remove level')"
                @click="preferences.removeGrouping(scope, level)">✕</button>
      </span>
      <NcButton variant="tertiary" @click="preferences.clearGrouping(scope)">
        {{ t('Clear') }}
      </NcButton>
    </div>

    <select v-if="addableLevels.length" value="" @change="onAddLevel">
      <option value="">
        {{ levels.length ? t('Add a level…') : t('Nothing') }}
      </option>
      <optgroup :label="t('File')">
        <option v-for="level in addableBuiltins" :key="level" :value="level">
          {{ groupLabel(t, level) }}
        </option>
      </optgroup>
      <optgroup v-if="addableMetadata.length" :label="t('Metadata')">
        <option v-for="field in addableMetadata" :key="field.field" :value="field.field">
          {{ field.label }}
        </option>
      </optgroup>
    </select>
    <span v-else class="muted">{{ t('Maximum levels reached') }}</span>

    <NcButton @click="columnsOpen = true">
      <template #icon>
        <Columns3 :size="20" />
      </template>
      {{ t('Columns') }}
    </NcButton>

    <!--
      The details panel is a view option like the columns, not something a click
      on a row conjures up: it is either beside the results or it is not, and it
      stays that way until you say otherwise.
    -->
    <NcCheckboxRadioSwitch :model-value="preferences.sidebarPinned"
                           type="switch"
                           class="view-options__panel"
                           @update:model-value="preferences.setSidebarPinned($event)">
      {{ t('Details panel') }}
    </NcCheckboxRadioSwitch>

    <span v-if="hint" class="view-options__hint muted">{{ hint }}</span>

    <NcDialog v-if="columnsOpen"
              :name="t('Columns')"
              size="normal"
              @closing="columnsOpen = false">
      <p class="muted">
        {{ t('Choose which columns the results show, in which order, and under which name.') }}
      </p>

      <ul class="columns">
        <li v-for="(column, index) in preferences.columns" :key="column.id" class="columns__row">
          <NcCheckboxRadioSwitch :model-value="column.visible"
                                 :disabled="column.id === 'name'"
                                 @update:model-value="preferences.toggle(column.id)" />

          <NcInputField :model-value="column.label"
                        class="columns__label"
                        :label="defaultHeaderOf(column)"
                        :placeholder="defaultHeaderOf(column)"
                        @update:model-value="preferences.rename(column.id, String($event))" />

          <NcButton variant="tertiary"
                    :disabled="index === 0"
                    :aria-label="t('Move up')"
                    @click="preferences.move(column.id, -1)">
            <template #icon>
              <ArrowUp :size="20" />
            </template>
          </NcButton>
          <NcButton variant="tertiary"
                    :disabled="index === preferences.columns.length - 1"
                    :aria-label="t('Move down')"
                    @click="preferences.move(column.id, 1)">
            <template #icon>
              <ArrowDown :size="20" />
            </template>
          </NcButton>
          <!-- Built-ins are only ever hidden; a metadata column the user added
               can go away entirely, or the list would grow without end. -->
          <NcButton v-if="isMetadataField(column.id)"
                    variant="tertiary"
                    :aria-label="t('Remove column')"
                    @click="preferences.remove(column.id)">
            <template #icon>
              <X :size="20" />
            </template>
          </NcButton>
          <span v-else class="columns__spacer" />
        </li>
      </ul>

      <label v-if="addableFields.length" class="columns__add">
        <span class="muted">{{ t('Add a metadata column:') }}</span>
        <select value="" @change="onAddColumn">
          <option value="">{{ t('Choose a field…') }}</option>
          <option v-for="field in addableFields" :key="field.field" :value="field.field">
            {{ field.label }}
          </option>
        </select>
      </label>

      <template #actions>
        <NcButton @click="preferences.reset()">
          {{ t('Reset to defaults') }}
        </NcButton>
        <NcButton variant="primary" @click="columnsOpen = false">
          {{ t('Done') }}
        </NcButton>
      </template>
    </NcDialog>
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import {
  NcButton,
  NcCheckboxRadioSwitch,
  NcDialog,
  NcInputField,
} from '@nextcloud/vue'
import { ArrowDown, ArrowUp, Columns3, X } from '@lucide/vue'
import { useI18n } from '../composables/useI18n'
import { MAX_GROUPING_LEVELS, usePreferencesStore } from '../stores/preferencesStore'
import { useSearchStore } from '../stores/searchStore'
import { groupLabel } from '../filters/grouping'
import { columnLabel } from '../filters/columns'
import { isMetadataField } from '../filters/metadata'
import type { ColumnPref, GroupScope } from '../types/Search'

const { t } = useI18n()
const preferences = usePreferencesStore()
const search = useSearchStore()

const props = defineProps<{
  /** True when only part of the result set is loaded — grouping then misleads. */
  partial?: boolean
  /** Which result list these options belong to; grouping is kept per list. */
  scope: GroupScope
}>()

const scope = computed(() => props.scope)

/** The grouping levels of this list only — the other page keeps its own. */
const levels = computed(() => preferences.groupingFor(props.scope))

const columnsOpen = ref(false)

const BUILTIN_LEVELS = ['folder', 'type', 'mimetype', 'modified', 'createdBy', 'modifiedBy']

const metadataFields = computed(() => search.metadata)

const atMaxLevels = computed(() => levels.value.length >= MAX_GROUPING_LEVELS)

const addableBuiltins = computed(() =>
  (atMaxLevels.value ? [] : BUILTIN_LEVELS.filter((l) => !levels.value.includes(l))))

const addableMetadata = computed(() =>
  (atMaxLevels.value ? [] : metadataFields.value.filter((f) => !levels.value.includes(f.field))))

const addableLevels = computed(() => [...addableBuiltins.value, ...addableMetadata.value])

/** Only fields the grid does not already carry, so the list shrinks as you add. */
const addableFields = computed(() => {
  const present = new Set(preferences.columns.map((c) => c.id))
  return metadataFields.value.filter((f) => !present.has(f.field))
})

/**
 * Grouping runs over the loaded rows, so on a partial result it describes the
 * page rather than the search. Say so rather than letting it read as a summary.
 */
const hint = computed(() =>
  (levels.value.length && props.partial
    ? t('Grouping covers the loaded results only — use Load all for the whole set.')
    : ''))

function levelLabel(level: string): string {
  return isMetadataField(level)
    ? columnLabel(t, level, metadataFields.value)
    : groupLabel(t, level)
}

/**
 * The built-in name, ignoring any rename — that is what the input edits.
 * @param column
 */
function defaultHeaderOf(column: ColumnPref): string {
  return columnLabel(t, column.id, metadataFields.value)
}

/**
 * Back to the placeholder after choosing, so the control reads as an action.
 * @param event
 * @param apply
 */
function consume(event: Event, apply: (value: string) => void) {
  const select = event.target as HTMLSelectElement
  if (select.value) {
    apply(select.value)
  }
  select.value = ''
}

function onAddLevel(event: Event) {
  consume(event, (value) => preferences.addGrouping(props.scope, value))
}

function onAddColumn(event: Event) {
  consume(event, (value) => preferences.addColumn(value))
}
</script>

<style scoped lang="scss">
.view-options {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
  margin-bottom: 8px;

  &__levels {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
  }

  &__hint {
    font-size: 90%;
  }
}

.chip {
  display: inline-flex;
  align-items: center;
  gap: 2px;
  padding: 2px 4px 2px 10px;
  border-radius: var(--border-radius-pill, 16px);
  background: var(--color-primary-element-light);

  &__label {
    font-size: 90%;
  }

  &__button {
    background: none;
    border: none;
    cursor: pointer;
    padding: 0 4px;
    color: inherit;
    line-height: 1;

    &:hover {
      color: var(--color-primary-element);
    }
  }
}

.columns {
  list-style: none;
  padding: 0;
  margin: 8px 0;

  &__row {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 2px 0;
  }

  &__label {
    flex: 1 1 auto;
  }

  &__spacer {
    width: 44px;
  }

  &__add {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 12px;
  }
}

.muted {
  color: var(--color-text-maxcontrast);
}
</style>
