<template>
  <DcnDialog :name="title"
             size="normal"
             @closing="emit('close', changed)">
    <div class="schedule-dialog">
      <p class="muted">
        {{ t('The search runs on its own at the time you choose. If it finds files that are new or changed since you last opened this search, you get a notification.') }}
      </p>

      <div class="schedule-dialog__when">
        <NcSelect v-model="frequencyOption"
                  label="label"
                  :options="frequencies"
                  :clearable="false"
                  :disabled="busy"
                  :input-label="t('When')" />
        <NcSelect v-if="frequency === 'weekly'"
                  v-model="weekdayOption"
                  label="label"
                  :options="weekdays"
                  :clearable="false"
                  :disabled="busy"
                  :input-label="t('Day')" />
        <NcDateTimePickerNative v-model="timeValue"
                                class="schedule-dialog__time"
                                type="time"
                                :label="t('Time')" />
      </div>

      <!--
        Said where the choice is made: the name travels with the notification
        to a phone and into reminder mails, the files never do.
      -->
      <NcNoteCard type="info" class="schedule-dialog__note">
        {{ t('The notification shows the name of this search, also on your phone. It never names the files.') }}
      </NcNoteCard>
      <p v-if="entry.shared" class="muted">
        {{ t('Only for you — not for the people this search is shared with.') }}
      </p>

      <NcNoteCard v-if="error" type="error">{{ error }}</NcNoteCard>
    </div>

    <template #actions>
      <NcButton v-if="entry.schedule"
                class="schedule-dialog__stop"
                variant="tertiary"
                :disabled="busy"
                @click="stop">
        {{ t('Stop notifying') }}
      </NcButton>
      <NcButton variant="tertiary" :disabled="busy" @click="emit('close', changed)">
        {{ t('Cancel') }}
      </NcButton>
      <NcButton variant="primary" :disabled="busy || time === ''" @click="submit">
        {{ entry.schedule ? t('Save') : t('Notify me') }}
      </NcButton>
    </template>
  </DcnDialog>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { NcButton, NcDateTimePickerNative, NcNoteCard, NcSelect } from '@nextcloud/vue'
import { DcnDialog } from '@doconext/nc-ui'
import { useI18n } from '../composables/useI18n'
import { SearchApi } from '../services/SearchApi'
import { DEFAULT_SCHEDULE, frequencyOptions, weekdayOptions } from '../filters/schedule'
import type { FrequencyOption, WeekdayOption } from '../filters/schedule'
import type { ScheduleFrequency, StoredSearch } from '../types/Search'

/**
 * When the user wants to hear what is new in one saved search — their own, or
 * one shared with them. The schedule is theirs alone either way.
 */
const props = defineProps<{
  /** The saved search to schedule; its `schedule` is the one being changed, if any. */
  entry: StoredSearch
}>()

/** `true` when the schedule was set, changed or stopped, so the opener reads its list again. */
const emit = defineEmits<{ (e: 'close', changed: boolean): void }>()

const { t } = useI18n()

const start = props.entry.schedule ?? DEFAULT_SCHEDULE
const frequency = ref<ScheduleFrequency>(start.frequency)
const weekday = ref(start.weekday || DEFAULT_SCHEDULE.weekday)
const time = ref(start.time)
const busy = ref(false)
const error = ref('')
const changed = ref(false)

const title = computed(() => t('Notify me about "{name}"', { name: props.entry.name ?? '' }))

const frequencies = computed(() => frequencyOptions(t))
const weekdays = computed(() => weekdayOptions())

const frequencyOption = computed({
  get: (): FrequencyOption | undefined => frequencies.value.find((option) => option.id === frequency.value),
  set: (option: FrequencyOption | undefined) => {
    frequency.value = option?.id ?? DEFAULT_SCHEDULE.frequency
  },
})

const weekdayOption = computed({
  get: (): WeekdayOption | undefined => weekdays.value.find((option) => option.id === weekday.value),
  set: (option: WeekdayOption | undefined) => {
    weekday.value = option?.id ?? DEFAULT_SCHEDULE.weekday
  },
})

/** HH:MM ⇄ the Date the time picker works in; only its hours and minutes count. */
const timeValue = computed({
  get: (): Date | null => {
    const [hours, minutes] = time.value.split(':').map(Number)
    if (!Number.isInteger(hours) || !Number.isInteger(minutes)) {
      return null
    }
    const date = new Date()
    date.setHours(hours, minutes, 0, 0)
    return date
  },
  set: (date: Date | null) => {
    const pad = (n: number) => String(n).padStart(2, '0')
    time.value = date ? `${pad(date.getHours())}:${pad(date.getMinutes())}` : ''
  },
})

async function submit() {
  await guard(() => SearchApi.setSchedule(props.entry.id, {
    frequency: frequency.value,
    weekday: frequency.value === 'weekly' ? weekday.value : 0,
    time: time.value,
  }))
}

async function stop() {
  await guard(() => SearchApi.removeSchedule(props.entry.id))
}

/**
 * Closes on success; stays open with the reason otherwise.
 * @param action the request to send
 */
async function guard(action: () => Promise<unknown>) {
  busy.value = true
  error.value = ''
  try {
    await action()
    changed.value = true
    emit('close', true)
  } catch (e) {
    error.value = (e as Error).message
  } finally {
    busy.value = false
  }
}
</script>

<style scoped lang="scss">
// Not `.dialog`: NcDialog names its own parts dialog__content, dialog__wrapper…
.schedule-dialog {
  p {
    margin: 0 0 8px;
  }

  // The three choices on one line where there is room, each as wide as the
  // Type and Modified filters on the Search page.
  &__when {
    display: flex;
    flex-wrap: wrap;
    align-items: end;
    gap: 8px 12px;
    margin: 8px 0 16px;

    > * {
      flex: 1 1 180px;
      min-width: 0;
    }
  }

  &__note {
    margin: 0 0 8px;
  }

  // NcSelect carries a 4px bottom margin and the row aligns on the end, so
  // without the same margin the time box hangs 4px below the one beside it.
  // Three classes: the picker's own margin rule must not tie with this one.
  & &__when &__time {
    margin-bottom: 4px;
  }
}

// In the actions row, pushed to the start: it ends something, the other two
// answer the dialog.
.schedule-dialog__stop {
  margin-inline-end: auto;
}

.muted {
  color: var(--color-text-maxcontrast);
}
</style>
