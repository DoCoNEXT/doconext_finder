/**
 * The widths the user dragged the result grid's columns to.
 *
 * Kept in this browser, not with the other column preferences on the server,
 * for the reason the details panel's width is (see useSidebarResize): a width
 * is a property of the screen it was chosen on, not of the person. The same
 * account is read on a laptop and on a monitor, and a column that suits one
 * suits the other badly. Which columns show, in what order and under what name
 * is about the person, and stays on the server.
 *
 * One map for the whole app, so Search and Favorites — which share their
 * columns — share their widths too.
 */
import { ref } from 'vue'
import { APP_ID } from '../constants'
import { COLUMN_WIDTH } from '../filters/columns'

/** Keyed by app id, since several DoCoNEXT apps share one Nextcloud session's localStorage. */
const STORAGE_KEY = `${APP_ID}-column-widths`

/** The stored widths by column id. Anything unusable is no width. */
function read(): Record<string, number> {
  let raw: string | null
  try {
    raw = window.localStorage.getItem(STORAGE_KEY)
  } catch {
    // Private windows and blocked site data throw on access; a forgotten
    // layout preference is not worth failing the app over.
    return {}
  }

  let parsed: unknown
  try {
    parsed = JSON.parse(raw ?? '{}')
  } catch {
    return {}
  }
  if (typeof parsed !== 'object' || parsed === null || Array.isArray(parsed)) {
    return {}
  }

  // Out of range means "no choice", not "nearest legal value": such a number
  // came from a hand-edited store or an older version, and guessing at what it
  // meant is worse than letting the column size itself again.
  const widths: Record<string, number> = {}
  for (const [id, width] of Object.entries(parsed)) {
    if (typeof width === 'number' && Number.isFinite(width) && width >= COLUMN_WIDTH.min && width <= COLUMN_WIDTH.max) {
      widths[id] = width
    }
  }
  return widths
}

function write(widths: Record<string, number>) {
  try {
    if (Object.keys(widths).length === 0) {
      window.localStorage.removeItem(STORAGE_KEY)
    } else {
      window.localStorage.setItem(STORAGE_KEY, JSON.stringify(widths))
    }
  } catch {
    // ignored on purpose — see above
  }
}

// Read once, at module load, so every caller lands on this same ref.
const widths = ref<Record<string, number>>(read())

export function useColumnWidths() {
  /**
   * @param id a column id
   * @return pixels, or null while the column sizes itself
   */
  function widthOf(id: string): number | null {
    return widths.value[id] ?? null
  }

  /**
   * @param id a column id
   * @param width pixels, or null to let the column size itself again
   */
  function setWidth(id: string, width: number | null) {
    const next = { ...widths.value }
    if (width === null) {
      delete next[id]
    } else {
      next[id] = width
    }
    widths.value = next
    write(next)
  }

  /** Every column sizes itself again. */
  function clearWidths() {
    widths.value = {}
    write({})
  }

  return { widthOf, setWidth, clearWidths }
}
