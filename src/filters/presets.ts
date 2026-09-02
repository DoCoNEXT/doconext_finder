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

export function fileTypePresets(t: Translate): FileTypePreset[] {
  return [
    anyType(t),
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

export interface ModifiedPreset {
  id: string
  label: string
  /** Seconds back from now, or null for "any time". */
  seconds: number | null
}

const DAY = 86400

export function anyTime(t: Translate): ModifiedPreset {
  return { id: 'any', label: t('Any time'), seconds: null }
}

export function modifiedPresets(t: Translate): ModifiedPreset[] {
  return [
    anyTime(t),
    { id: 'today', label: t('Today'), seconds: DAY },
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
  return preset.seconds === null
    ? null
    : Math.floor(Date.now() / 1000) - preset.seconds
}
