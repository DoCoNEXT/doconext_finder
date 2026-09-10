/**
 * Dragging the details panel wider or narrower, and remembering where it was
 * left.
 *
 * NcAppSidebar sizes itself — `clamp(300px, 27vw, 500px)` — and offers no way to
 * change that, which is fine for a column of key/value rows and poor for a panel
 * that leads with a preview of a document. This app used to answer that by
 * hard-coding a wider clamp of its own, which only moved the guess: wide enough
 * for a scanned page is wider than anyone wants while reading a list of names.
 * So the width is the reader's to set, and the app's clamp becomes only where it
 * starts.
 *
 * The panel follows the pointer by writing a custom property straight onto
 * <html>, not through a reactive binding: this component's template is large,
 * and re-rendering it on every pointer move to move one edge is the difference
 * between a drag that tracks the cursor and one that lags behind it. The store
 * hears about it once, on release.
 */
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { usePreferencesStore } from '../stores/preferencesStore'

/**
 * The property the panel's width is read from — declared, with its default, in
 * the stylesheet that owns the panel. Set here as an inline property on <html>,
 * which outranks any stylesheet rule without either side having to know the
 * other's specificity.
 */
const WIDTH_PROPERTY = '--dcn-sidebar-width'

/** Marks a drag in progress, so the page can stop selecting text under it. */
const RESIZING_CLASS = 'dcn-sidebar-resizing'

/** Mirrors PreferencesService::MIN_SIDEBAR_WIDTH / MAX_SIDEBAR_WIDTH. */
const MIN_WIDTH = 300
const MAX_WIDTH = 1200

/**
 * What the result list keeps for itself however far the panel is dragged. A grid
 * of file names much below this is a column of ellipses, and burying it is not
 * something a drag towards the panel means to say.
 */
const MIN_CONTENT_WIDTH = 420

/** One arrow press. A pixel at a time is a keyboard control in name only. */
const KEY_STEP = 16

export function useSidebarResize() {
  const preferences = usePreferencesStore()

  /** What the separator reports to a screen reader; also what a nudge starts from. */
  const width = ref(0)

  /**
   * A width the window can actually hold.
   *
   * Clamped against the viewport as well as against the stored bounds, because a
   * width is kept on the account: one set on a wide monitor is read back on a
   * laptop, where honouring it would leave the results a sliver. The ceiling
   * never falls below the floor, so a narrow window gives the panel its minimum
   * rather than an impossible range.
   * @param wanted the width being asked for, in pixels
   */
  function allowed(wanted: number): number {
    const ceiling = Math.max(MIN_WIDTH, Math.min(MAX_WIDTH, window.innerWidth - MIN_CONTENT_WIDTH))

    return Math.round(Math.min(Math.max(wanted, MIN_WIDTH), ceiling))
  }

  /**
   * @param wanted the width to show, or null to hand the panel back its own
   *   sizing — the stylesheet's default, which follows the window
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

  /** The width the panel is actually drawn at, which is the only honest answer
   * while it is still sizing itself. 0 until it has been laid out. */
  function measured(): number {
    return Math.round(document.getElementById('app-sidebar-vue')?.getBoundingClientRect().width ?? 0)
  }

  /** The stored width, or null while the panel has never been dragged. */
  function stored(): number | null {
    return preferences.sidebarWidth > 0 ? preferences.sidebarWidth : null
  }

  // Also covers the panel appearing before the preferences have loaded, and a
  // reset from anywhere else in the app.
  watch(() => preferences.sidebarWidth, () => paint(stored()), { immediate: true })

  // A stored width outgrows a window that is made smaller; re-clamping keeps the
  // results reachable without touching what was stored, so widening the window
  // gives the panel back the width it was left at.
  function onWindowResize() {
    paint(stored())
  }

  onMounted(() => {
    window.addEventListener('resize', onWindowResize)
    // After layout: the panel is measured, and until it has been drawn there is
    // nothing to measure.
    requestAnimationFrame(() => {
      if (stored() === null) {
        width.value = measured()
      }
    })
  })

  onBeforeUnmount(() => {
    window.removeEventListener('resize', onWindowResize)
    document.documentElement.classList.remove(RESIZING_CLASS)
  })

  const resizing = ref(false)
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
    // The width on screen is already the one being stored, so the write is not
    // something the page is waiting for.
    preferences.setSidebarWidth(width.value)
  }

  /**
   * Arrow keys move the edge; Home and End take it to its limits. The pattern is
   * the ARIA window splitter's, which is what `role="separator"` promises.
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
      case 'Enter': resetWidth(); event.preventDefault(); return
      default: return
    }

    event.preventDefault()
    paint(wanted)
    preferences.setSidebarWidth(width.value)
  }

  /**
   * Back to sizing itself to the window, which is where the panel started. The
   * watcher above does the painting, so this says the one thing it means.
   */
  function resetWidth() {
    preferences.setSidebarWidth(0)
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
