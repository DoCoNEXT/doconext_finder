/**
 * Collapsing the app navigation to a rail of icons instead of hiding it.
 *
 * NcAppNavigation's own toggle takes the whole sidebar away: the buttons don't
 * shrink, they disappear, and with them any way to switch pages without opening
 * the sidebar back up. Every desktop file browser collapses to icons instead.
 *
 * The component has no rail mode and no `open` prop, and when it closes it marks
 * its <nav> `inert` and `aria-hidden` — correct for something that is gone,
 * wrong for something still on screen. So this does not use it: the navigation
 * is left permanently open and the collapsing is ours, which is why nothing here
 * has to observe the DOM, undo an ARIA attribute or out-shout a library rule.
 * The library's toggle is hidden in the accompanying stylesheet, and a rail
 * toggle takes its job.
 *
 * On mobile the navigation is an overlay rather than a column, and a permanent
 * rail would sit on top of the content — so there it keeps its normal
 * open/closed behaviour and this stays out of the way.
 *
 * Reusable as-is: pair it with styles/navigation-rail.scss (or a plain-CSS
 * port of it), render a toggle bound to `toggleRail` while `canRail` inside
 * NcAppNavigation's `#search` slot, and put `railClass` on NcContent.
 *
 * `collapsed` is a module-level singleton rather than a fresh ref per call —
 * mirroring how `@nextcloud/vue`'s own useIsMobile() shares one ref across every
 * caller. An app can need the same live answer in more than one place at once:
 * NcContent's class and the toggle button both live in the shell, and a bigger
 * app can also have its navigation split across a couple of components (a
 * settings sidebar with its own instance, say), each wanting to know the same
 * thing. A ref created fresh inside the function would only agree on the
 * localStorage key between those call sites — not on live state, so toggling
 * in one place would go unnoticed by another until a full reload.
 */
import { computed, ref } from 'vue'
import { useIsMobile } from '@nextcloud/vue'
import { APP_ID } from '../constants'

/** The class the stylesheet keys off. Put it on NcContent. */
export const RAIL_CLASS = 'app--rail'

/**
 * A collapsed sidebar is expected to stay collapsed across reloads, and it is a
 * per-device layout preference, so it belongs in this browser rather than on
 * the account. Keyed by app id: several DoCoNEXT apps can share one Nextcloud
 * session's localStorage, and each remembers its own rail independently.
 */
const STORAGE_KEY = `${APP_ID}-navigation-rail`

function read(): boolean {
  try {
    return window.localStorage.getItem(STORAGE_KEY) === 'collapsed'
  } catch {
    // Private windows and blocked site data throw on access; a forgotten
    // layout preference is not worth failing the app over.
    return false
  }
}

function write(value: boolean) {
  try {
    window.localStorage.setItem(STORAGE_KEY, value ? 'collapsed' : 'expanded')
  } catch {
    // ignored on purpose — see above
  }
}

// Read once, at module load, not inside useNavigationRail(): every caller
// across the whole page must land on this same ref instance, not a copy of it.
const collapsed = ref(read())

export function useNavigationRail() {
  const isMobile = useIsMobile()

  /** A rail only makes sense where the navigation is a column beside content. */
  const canRail = computed(() => !isMobile.value)
  const railed = computed(() => collapsed.value && canRail.value)
  const railClass = computed(() => ({ [RAIL_CLASS]: railed.value }))

  function toggleRail() {
    collapsed.value = !collapsed.value
    write(collapsed.value)
  }

  return { railed, canRail, railClass, toggleRail }
}
