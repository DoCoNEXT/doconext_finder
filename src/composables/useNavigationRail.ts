/**
 * Collapsing the navigation to a rail of icons instead of hiding it.
 *
 * NcAppNavigation's own toggle takes the whole sidebar away: the buttons don't
 * shrink, they disappear, and with them any way to switch pages without opening
 * the sidebar back up. Every desktop file browser collapses to icons instead,
 * and so should this.
 *
 * The component exposes no rail mode and no `open` prop, so this reads the one
 * thing it does publish: the `app-navigation--closed` class it puts on its own
 * container. Watching the DOM rather than the `navigation-toggled` event on the
 * bus is deliberate — that event is emitted a full animation later, which is
 * long enough to watch the sidebar slide out and snap back as a rail.
 *
 * While collapsed the component also marks the element `inert` and
 * `aria-hidden`, which is right for something that is gone and wrong for
 * something still on screen. Those are restored here; the width and the hidden
 * labels are CSS.
 *
 * On mobile the navigation is an overlay rather than a column, and a permanent
 * rail there would sit on top of the content — so on mobile it keeps closing.
 */
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useIsMobile } from '@nextcloud/vue'

const NAV_ID = 'app-navigation-vue'
const CLOSED_CLASS = 'app-navigation--closed'

export function useNavigationRail() {
  const isMobile = useIsMobile()
  /** True while the navigation is collapsed to icons. */
  const railed = ref(false)

  let observer: MutationObserver | null = null

  onMounted(() => {
    const nav = document.getElementById(NAV_ID)
    const container = nav?.parentElement
    if (!nav || !container) {
      return
    }

    const sync = () => {
      railed.value = container.classList.contains(CLOSED_CLASS) && !isMobile.value

      if (!railed.value) {
        return
      }
      if (nav.hasAttribute('inert')) {
        nav.removeAttribute('inert')
      }
      if (nav.getAttribute('aria-hidden') === 'true') {
        nav.setAttribute('aria-hidden', 'false')
      }
    }

    observer = new MutationObserver(sync)
    // Subtree: the class sits on the container, the two attributes on the <nav>
    // inside it, and both change in the same render.
    observer.observe(container, {
      attributes: true,
      subtree: true,
      attributeFilter: ['class', 'inert', 'aria-hidden'],
    })

    sync()
  })

  onBeforeUnmount(() => observer?.disconnect())

  return { railed }
}
