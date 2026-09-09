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

export interface ModifiedPreset {
  id: string
  label: string
  /**
   * Seconds back from now, null for "any time", or 'midnight' for a preset
   * that means a calendar day rather than a rolling window.
   */
  seconds: number | null | 'midnight'
}

const DAY = 86400

export function anyTime(t: Translate): ModifiedPreset {
  return { id: 'any', label: t('Any time'), seconds: null }
}

export function modifiedPresets(t: Translate): ModifiedPreset[] {
  return [
    anyTime(t),
    // Since midnight, not the last 24 hours: at eleven in the morning a rolling
    // day still reaches back into yesterday evening, and a list headed "Today"
    // showing yesterday's files reads as a bug — which is how this was found.
    // The other presets are honestly named as rolling windows and stay that way.
    { id: 'today', label: t('Today'), seconds: 'midnight' },
    { id: 'week', label: t('Last 7 days'), seconds: 7 * DAY },
    { id: 'month', label: t('Last 30 days'), seconds: 30 * DAY },
    { id: 'year', label: t('Last 12 months'), seconds: 365 * DAY },
  ]
}

/**
 * Resolves a preset to an absolute cutoff in Unix seconds, or null.
 * @param preset
 */
export function modifiedAfter(preset: ModifiedPreset): number | null {
  if (preset.seconds === null) {
    return null
  }

  if (preset.seconds === 'midnight') {
    // The viewer's own midnight: the server stores UTC seconds, but "today" is
    // a question about the calendar the person asking is looking at.
    const midnight = new Date()
    midnight.setHours(0, 0, 0, 0)

    return Math.floor(midnight.getTime() / 1000)
  }

  return Math.floor(Date.now() / 1000) - preset.seconds
}
