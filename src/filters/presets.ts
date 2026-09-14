/**
 * The preset filter bar: the two choices people actually reach for, expressed
 * as ready-made filters so nobody has to hand-build a condition on a raw
 * database column to answer "PDFs from this year".
 *
 * Both presets are sent as their own request fields rather than as conditions,
 * because they AND with everything — switching the condition group to "match
 * any" must not widen the chosen type or date range into an alternative.
 *
 * The lists are built by functions taking `t` rather than exported as
 * constants: a constant would be translated once at module load, before the
 * l10n bundle is registered, and would then never follow a language change.
 * Passing `t` in also keeps this module free of a dependency on the composable.
 */

import { FILE_TYPE_FILTERS } from '../constants'

export type Translate = (_text: string, _vars?: Record<string, unknown>) => string

export interface FileTypePreset {
  id: string
  label: string
  /**
   * Exact mimetypes, or a single trailing wildcard ("image/%"). Partial
   * wildcards are rejected by the search backend, so office formats are listed
   * exactly rather than matched on a fragment.
   */
  mimetypes: string[]
}

/**
 * The neutral default, so callers never index into the list for it.
 * @param t
 */
export function anyType(t: Translate): FileTypePreset {
  return { id: 'any', label: t('Any type'), mimetypes: [] }
}

/**
 * Every builtin category this app ships, labelled and with its mimetype list —
 * the registry {@link fileTypePresets} resolves admin-configured ids against.
 * Ids here must stay in sync with `AppConstants::BUILTIN_FILE_TYPE_IDS` on the
 * server, which validates admin-submitted ids against the same set.
 * @param t
 */
export function builtinFileTypeDefinitions(t: Translate): FileTypePreset[] {
  return [
    {
      id: 'files',
      label: t('Files'),
      // The complement of 'folders': every top-level mimetype family except
      // httpd/unix-directory. Spelled out because the backend takes a list of
      // mimetypes to match, and has no way to express "anything but".
      mimetypes: ['application/%', 'text/%', 'image/%', 'video/%', 'audio/%', 'message/%'],
    },
    {
      id: 'documents',
      label: t('Documents'),
      mimetypes: [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.oasis.opendocument.text',
        'text/plain',
        'text/markdown',
      ],
    },
    {
      id: 'spreadsheets',
      label: t('Spreadsheets'),
      mimetypes: [
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.oasis.opendocument.spreadsheet',
        'text/csv',
      ],
    },
    {
      id: 'presentations',
      label: t('Presentations'),
      mimetypes: [
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.oasis.opendocument.presentation',
      ],
    },
    { id: 'pdf', label: t('PDFs'), mimetypes: ['application/pdf'] },
    { id: 'images', label: t('Images'), mimetypes: ['image/%'] },
    { id: 'video', label: t('Video'), mimetypes: ['video/%'] },
    { id: 'audio', label: t('Audio'), mimetypes: ['audio/%'] },
    {
      id: 'email',
      label: t('Emails'),
      mimetypes: ['message/rfc822', 'application/vnd.ms-outlook'],
    },
    {
      id: 'archives',
      label: t('Archives'),
      mimetypes: [
        'application/zip',
        'application/x-tar',
        'application/gzip',
        'application/x-7z-compressed',
        'application/vnd.rar',
      ],
    },
    { id: 'folders', label: t('Folders'), mimetypes: ['httpd/unix-directory'] },
  ]
}

/**
 * The type filter dropdown's options: "Any type" plus whatever the admin
 * configured, in their configured order. A builtin entry is resolved against
 * {@link builtinFileTypeDefinitions} (dropped if its id is no longer known —
 * e.g. an app update retired it); a custom entry carries its own label and
 * mimetype list already.
 * @param t
 */
export function fileTypePresets(t: Translate): FileTypePreset[] {
  const builtins = new Map(builtinFileTypeDefinitions(t).map((preset) => [preset.id, preset]))

  const configured = FILE_TYPE_FILTERS.map((entry) => (
    entry.type === 'custom'
      ? { id: entry.id, label: entry.label, mimetypes: entry.mimetypes }
      : builtins.get(entry.id)
  )).filter((preset): preset is FileTypePreset => preset !== undefined)

  return [anyType(t), ...configured]
}

/**
 * The window a preset stands for, in Unix seconds. Both ends are optional: most
 * presets are open-ended ("since last Tuesday"), and only a closed period — a
 * calendar year that has finished — needs an upper bound too.
 */
export interface ModifiedRange {
  after: number | null
  before: number | null
}

export interface ModifiedPreset {
  id: string
  label: string
  /**
   * Resolved against the clock at *search* time, never at list-building time:
   * "Last 7 days" must mean seven days before this run, which is exactly why
   * stored searches keep preset ids instead of the timestamps behind them.
   */
  range: () => ModifiedRange
}

const DAY = 86400

const now = (): number => Math.floor(Date.now() / 1000)

/**
 * The viewer's own midnight: the server stores UTC seconds, but "today" is a
 * question about the calendar the person asking is looking at. Same for a year
 * boundary, which is why these go through a local Date rather than arithmetic
 * on epoch seconds.
 */
function startOfToday(): number {
  const midnight = new Date()
  midnight.setHours(0, 0, 0, 0)

  return Math.floor(midnight.getTime() / 1000)
}

function startOfYear(year: number): number {
  return Math.floor(new Date(year, 0, 1, 0, 0, 0, 0).getTime() / 1000)
}

/**
 * `monthsBack` counts backwards from the current month; the Date constructor
 * takes a month of -1 and lands in December of the year before, which is why
 * no year arithmetic is needed here.
 * @param monthsBack
 */
function startOfMonth(monthsBack: number): number {
  const today = new Date()

  return Math.floor(new Date(today.getFullYear(), today.getMonth() - monthsBack, 1, 0, 0, 0, 0).getTime() / 1000)
}

/** A window reaching back from this moment, with no upper bound. */
function since(seconds: number): ModifiedRange {
  return { after: now() - seconds, before: null }
}

export function anyTime(t: Translate): ModifiedPreset {
  return { id: 'any', label: t('Any time'), range: () => ({ after: null, before: null }) }
}

/**
 * The wording is Nextcloud's own, so a date filter means the same thing wherever
 * in the server someone meets it: every name here that the unified search dialog
 * or the Files list filter also offers stands for exactly the period it does
 * there. The two month presets are this app's own addition — the two Nextcloud
 * screens have no equivalent.
 *
 * Every label is a fixed string, naming no year and no month. That keeps the
 * list independent of when it was built: only {@link ModifiedPreset.range}
 * reads the clock, so a page left open across New Year can never label a period
 * as one year while searching another.
 * @param t
 */
export function modifiedPresets(t: Translate): ModifiedPreset[] {
  return [
    anyTime(t),
    // Since midnight, not the last 24 hours: at eleven in the morning a rolling
    // day still reaches back into yesterday evening, and a list headed "Today"
    // showing yesterday's files reads as a bug — which is how this was found.
    // The day counts below are honestly named as rolling windows and stay that
    // way; the month and year presets are calendar periods, as their names
    // promise.
    { id: 'today', label: t('Today'), range: () => ({ after: startOfToday(), before: null }) },
    { id: 'week', label: t('Last 7 days'), range: () => since(7 * DAY) },
    { id: 'month', label: t('Last 30 days'), range: () => since(30 * DAY) },
    // Calendar months, unlike the day counts above them: "Last 30 days" on the
    // 5th of the month is mostly last month's files, which is precisely the
    // question these two answer instead.
    { id: 'this-month', label: t('This month'), range: () => ({ after: startOfMonth(0), before: null }) },
    {
      id: 'last-month',
      label: t('Last month'),
      range: () => ({ after: startOfMonth(1), before: startOfMonth(0) }),
    },
    {
      id: 'this-year',
      label: t('This year'),
      range: () => ({ after: startOfYear(new Date().getFullYear()), before: null }),
    },
    {
      // Closed at both ends, like "Last month": a finished year ends where the
      // current one begins, so files touched this morning stay out of it.
      id: 'last-year',
      label: t('Last year'),
      range: () => {
        const current = new Date().getFullYear()

        return { after: startOfYear(current - 1), before: startOfYear(current) }
      },
    },
  ]
}

/**
 * Preset ids this app has since renamed.
 *
 * `year` was a rolling twelve months before the presets took Nextcloud's
 * wording, and the nearest thing it can still mean is the calendar year to
 * date. It is mapped rather than dropped because an id no preset answers to
 * falls back to "Any time" — which would quietly widen every stored search that
 * had a date filter at all, the one failure a date filter must not have.
 */
const RENAMED_PRESETS: Record<string, string> = { year: 'this-year' }

/**
 * The preset id a stored search means today. Call this on anything read back
 * from storage before looking it up.
 * @param id
 */
export function canonicalModifiedPreset(id: string): string {
  return RENAMED_PRESETS[id] ?? id
}

/**
 * Resolves a preset to absolute Unix seconds, open at either end.
 * @param preset
 */
export function modifiedRange(preset: ModifiedPreset): ModifiedRange {
  return preset.range()
}
