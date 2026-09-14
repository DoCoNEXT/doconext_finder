/**
 * Dragging the details panel wider or narrower, and remembering where it was
 * left.
 *
 * NcAppSidebar sizes itself — `clamp(300px, 27vw, 500px)` — and offers no way
 * to change that. That is a fair width for a column of key/value rows and a
 * poor one for a panel that shows a document, a preview or a long form, and an
 * app that needs more room has only ever been able to hard-code a wider clamp
 * of its own. That moves the guess rather than settling it: wide enough to read
 * a page is wider than anyone wants while scanning the list beside it. So the
 * width is the reader's to set, and the app's clamp becomes only where the
 * panel opens.
 *
 * Reusable as-is: pair it with `styles/sidebar-resize.scss` (or a plain-CSS port
 * of it) and render the handle as a **sibling** of NcAppSidebar — the stylesheet
 * says what that sibling's parent has to be. NcAppSidebar has no slot at its own
 * root, which is why the handle cannot live inside it.
 *
 * The panel follows the pointer by writing a custom property straight onto
 * <html> rather than through a reactive binding: a details panel is usually the
 * largest template in the app, and re-rendering it on every pointer move to
 * shift one edge is the difference between a drag that tracks the cursor and one
 * that trails it. Storage hears about it once, on release.
 *
 * The state is module-level rather than a fresh ref per call, mirroring
 * `useNavigationRail`: an app can render a sidebar from more than one route
 * component, and two copies would agree on the storage key but not on live
 * state — so the panel would keep its width while the handle reported someone
 * else's.
 *
 * Ported from doconext_app_template, which carries the identical file (Nextcloud
 * apps bundle their own JavaScript, so this is copied rather than imported).
 */
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { APP_ID } from '../constants'

/**
 * The property the panel's width is read from. Set here as an inline property
 * on <html>, which outranks any stylesheet rule without either side having to
 * know the other's specificity — and which is why no stylesheet may declare
 * this name. An app that wants a different opening width sets
 * `--dcn-sidebar-width-initial`; see the stylesheet.
 */
const WIDTH_PROPERTY = '--dcn-sidebar-width'

/** Marks a drag in progress, so the page stops selecting text under it. */
const RESIZING_CLASS = 'dcn-sidebar-resizing'

/**
 * A width is a property of the screen it was chosen on, not of the person: the
 * same account is read on a laptop and on a monitor, and a number that suits one
 * suits the other badly. So it lives in this browser, like the navigation rail's
 * collapsed state. Keyed by app id, since several DoCoNEXT apps share one
 * Nextcloud session's localStorage.
 */
const STORAGE_KEY = `${APP_ID}-sidebar-width`

/** The panel is never dragged narrower than this, whatever the window allows. */
const MIN_WIDTH = 300

/** Nor wider: past this it stops being a panel beside the page. */
const MAX_WIDTH = 1200

/**
 * What the page keeps for itself however far the panel is dragged. A list much
 * below this is a column of ellipses, and burying it is not something a drag
 * towards the panel means to say.
 */
const MIN_CONTENT_WIDTH = 420

/** One arrow press. A pixel at a time is a keyboard control in name only. */
const KEY_STEP = 16

/** The stored width, or null while the panel has never been dragged. */
function read(): number | null {
  let raw: string | null
  try {
    raw = window.localStorage.getItem(STORAGE_KEY)
  } catch {
    // Private windows and blocked site data throw on access; a forgotten
    // layout preference is not worth failing the app over.
    return null
  }

  const width = Number(raw)

  // Out of range means "no choice", not "nearest legal value": such a number
  // came from a hand-edited store or an older version, and guessing at what it
  // meant is worse than letting the panel size itself again.
  return Number.isFinite(width) && width >= MIN_WIDTH && width <= MAX_WIDTH ? width : null
}

function write(width: number | null) {
  try {
    if (width === null) {
      window.localStorage.removeItem(STORAGE_KEY)
    } else {
      window.localStorage.setItem(STORAGE_KEY, String(width))
    }
  } catch {
    // ignored on purpose — see above
  }
}

// Read once, at module load, so every caller lands on this same ref.
const chosen = ref<number | null>(read())

/** What the panel is drawn at now — what the separator reports, and what a nudge starts from. */
const width = ref(0)

const resizing = ref(false)

export function useSidebarResize() {
  /**
   * A width the window can actually hold.
   *
   * Clamped against the viewport as well as against the bounds, so a width
   * chosen while the window was wide gives way when it is made narrow — and
   * comes back when it grows again, because what was stored is never rewritten
   * to fit. The ceiling never falls below the floor, so a narrow window gives
   * the panel its minimum rather than an impossible range.
   * @param wanted the width being asked for, in pixels
   */
  function allowed(wanted: number): number {
    const ceiling = Math.max(MIN_WIDTH, Math.min(MAX_WIDTH, window.innerWidth - MIN_CONTENT_WIDTH))

    return Math.round(Math.min(Math.max(wanted, MIN_WIDTH), ceiling))
  }

  /**
   * The width the panel is actually drawn at, which is the only honest answer
   * while it is still sizing itself. 0 until it has been laid out.
   */
  function measured(): number {
    return Math.round(document.getElementById('app-sidebar-vue')?.getBoundingClientRect().width ?? 0)
  }

  /**
   * @param wanted the width to show, or null to hand the panel back its own
   *   sizing — the stylesheet's opening width, which follows the window
   */
  function paint(wanted: number | null) {
    const style = document.documentElement.style
    if (wanted === null) {
      style.removeProperty(WIDTH_PROPERTY)
      width.value = measured()
      return
    }

    width.value = allowed(wanted)
    style.setProperty(WIDTH_PROPERTY, `${width.value}px`)
  }

  /**
   * @param wanted the width to keep for next time, or null to forget it
   */
  function remember(wanted: number | null) {
    chosen.value = wanted
    write(wanted)
  }

  // A stored width outgrows a window that is made smaller; re-clamping keeps the
  // page reachable without touching what was stored.
  function onWindowResize() {
    paint(chosen.value)
  }

  onMounted(() => {
    window.addEventListener('resize', onWindowResize)
    // After layout: until the panel has been drawn there is nothing to measure,
    // and a panel that has never been dragged is measured rather than set.
    requestAnimationFrame(() => paint(chosen.value))
  })

  onBeforeUnmount(() => {
    window.removeEventListener('resize', onWindowResize)
    document.documentElement.classList.remove(RESIZING_CLASS)
  })

  let startX = 0
  let startWidth = 0

  /**
   * The panel hangs off the inline-end edge, so dragging its handle towards the
   * start of the line widens it — and in a right-to-left document that is the
   * other direction on screen.
   */
  function towardsStart(): number {
    return document.documentElement.dir === 'rtl' ? 1 : -1
  }

  /**
   * @param event the press that starts the drag
   */
  function startResize(event: PointerEvent) {
    const handle = event.currentTarget as HTMLElement
    // The handle captures the pointer, so the drag survives the cursor leaving
    // it — which it does immediately, since the edge moves out from under it.
    handle.setPointerCapture(event.pointerId)
    startX = event.clientX
    startWidth = width.value || measured()
    resizing.value = true
    document.documentElement.classList.add(RESIZING_CLASS)
    event.preventDefault()
  }

  /**
   * @param event the move; ignored unless a drag is running
   */
  function resizeTo(event: PointerEvent) {
    if (!resizing.value) {
      return
    }
    paint(startWidth + towardsStart() * (event.clientX - startX))
  }

  function endResize() {
    if (!resizing.value) {
      return
    }
    resizing.value = false
    document.documentElement.classList.remove(RESIZING_CLASS)
    remember(width.value)
  }

  /**
   * Arrow keys move the edge, Home and End take it to its limits, Enter gives
   * the panel its own sizing back. The pattern is the ARIA window splitter's,
   * which is what `role="separator"` promises.
   * @param event the key press
   */
  function nudge(event: KeyboardEvent) {
    const from = width.value || measured()

    // An arrow moves the edge the way it points, which widens the panel on one
    // side of the document and narrows it on the other — the same sign a drag
    // in that direction carries.
    let wanted: number
    switch (event.key) {
      case 'ArrowLeft': wanted = from + towardsStart() * -KEY_STEP; break
      case 'ArrowRight': wanted = from + towardsStart() * KEY_STEP; break
      case 'Home': wanted = MIN_WIDTH; break
      case 'End': wanted = MAX_WIDTH; break
      case 'Enter': event.preventDefault(); resetWidth(); return
      default: return
    }

    event.preventDefault()
    paint(wanted)
    remember(width.value)
  }

  /** Back to sizing itself to the window, which is where the panel started. */
  function resetWidth() {
    remember(null)
    paint(null)
  }

  return {
    width,
    resizing,
    minWidth: MIN_WIDTH,
    maxWidth: MAX_WIDTH,
    startResize,
    resizeTo,
    endResize,
    nudge,
    resetWidth,
  }
}
