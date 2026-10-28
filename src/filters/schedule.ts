/**
 * How a scheduled search reads on screen: when it runs, since when something
 * counts as new, and how much it found.
 *
 * Functions taking `t`, for the reason presets.ts gives: a list translated at
 * module load would never follow a language change.
 */
import { SCHEDULE_COUNT_LIMIT } from '../constants'
import type { Translate } from './presets'
import type { ScheduleFrequency, SearchSchedule } from '../types/Search'

/** What the dialog starts from: the weekday morning the user asked for. */
export const DEFAULT_SCHEDULE: Pick<SearchSchedule, 'frequency' | 'weekday' | 'time'> = {
  frequency: 'weekdays',
  weekday: 5,
  time: '08:00',
}

export interface FrequencyOption {
  id: ScheduleFrequency
  label: string
}

/**
 * @param t translation function
 */
export function frequencyOptions(t: Translate): FrequencyOption[] {
  return [
    { id: 'weekdays', label: t('Every weekday') },
    { id: 'daily', label: t('Every day') },
    { id: 'weekly', label: t('Every week') },
  ]
}

export interface WeekdayOption {
  /** ISO, 1 = Monday. */
  id: number
  label: string
}

/**
 * The days of the week in the reader's own language, Monday first, as ISO
 * numbers them. From the browser rather than the catalogue: seven more strings
 * to translate that every locale already has.
 */
export function weekdayOptions(): WeekdayOption[] {
  const format = new Intl.DateTimeFormat(undefined, { weekday: 'long' })
  // 5 January 2026 is a Monday.
  return Array.from({ length: 7 }, (_, index) => ({
    id: index + 1,
    label: format.format(new Date(2026, 0, 5 + index)),
  }))
}

/**
 * "Every weekday at 08:00", for the row and its tooltip.
 * @param t translation function
 * @param schedule the reader's schedule
 */
export function describeSchedule(t: Translate, schedule: SearchSchedule): string {
  switch (schedule.frequency) {
    case 'daily':
      return t('Every day at {time}', { time: schedule.time })
    case 'weekly': {
      const day = weekdayOptions().find((option) => option.id === schedule.weekday)?.label ?? ''
      return t('Every {day} at {time}', { day, time: schedule.time })
    }
    default:
      return t('Every weekday at {time}', { time: schedule.time })
  }
}

/**
 * "12 new", or "100+ new" past what a run counts.
 * @param t translation function
 * @param unseen what the last run found
 */
export function unseenLabel(t: Translate, unseen: number): string {
  const count = unseen > SCHEDULE_COUNT_LIMIT ? `${SCHEDULE_COUNT_LIMIT}+` : String(unseen)
  return t('{count} new', { count })
}

/**
 * The moment something counts as new from, as the reader's clock shows it.
 * @param unixSeconds seenAt
 */
export function sinceLabel(unixSeconds: number): string {
  return new Date(unixSeconds * 1000).toLocaleString(undefined, {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
  })
}
