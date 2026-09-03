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
 * Reusable as-is: pair it with styles/navigation-rail.scss, render a toggle
 * bound to `toggleRail` while `canRail`, and put `railClass` on NcContent.
 */
import { computed, ref } from 'vue'
import { useIsMobile } from '@nextcloud/vue'

/** The class the stylesheet keys off. Put it on NcContent. */
export const RAIL_CLASS = 'app--rail'

/**
 * @param storageKey where to remember the choice. A collapsed sidebar is
 *   expected to stay collapsed across reloads, and it is a per-device layout
 *   preference, so it belongs in this browser rather than on the account.
 */
export function useNavigationRail(storageKey = 'navigation-rail') {
  const isMobile = useIsMobile()

  const collapsed = ref(read())

  /** A rail only makes sense where the navigation is a column beside content. */
  const canRail = computed(() => !isMobile.value)
  const railed = computed(() => collapsed.value && canRail.value)
  const railClass = computed(() => ({ [RAIL_CLASS]: railed.value }))

  function toggleRail() {
    collapsed.value = !collapsed.value
    write(collapsed.value)
  }

  function read(): boolean {
    try {
      return window.localStorage.getItem(storageKey) === 'collapsed'
    } catch {
      // Private windows and blocked site data throw on access; a forgotten
      // layout preference is not worth failing the app over.
      return false
    }
  }

  function write(value: boolean) {
    try {
      window.localStorage.setItem(storageKey, value ? 'collapsed' : 'expanded')
    } catch {
      // ignored on purpose — see above
    }
  }

  return { railed, canRail, railClass, toggleRail }
}
