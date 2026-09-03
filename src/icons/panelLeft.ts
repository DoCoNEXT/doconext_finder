/**
 * The two rail-toggle icons, as raw SVG rather than an `@mdi/js` path.
 *
 * They are Lucide's `panel-left-close` and `panel-left-open`, copied verbatim
 * from what `@lucide/vue` renders, so that this app's toggle is the same icon
 * as the one in the DoCoNEXT apps built on that set — a control that means the
 * same thing in two apps should not be drawn twice differently. Copied rather
 * than imported: it is two icons, and a second icon library for them would cost
 * more than it explains.
 *
 * `fill="none"` sits on each shape, not only on the <svg>: NcIconSvgWrapper's
 * own stylesheet sets `fill: currentColor` on the element it is given, which
 * the children would otherwise inherit — filling a stroke-drawn icon solid.
 */
const ATTRS = 'xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"'
  + ' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"'

const FRAME = '<rect width="18" height="18" x="3" y="3" rx="2" fill="none"/>'
  + '<path d="M9 3v18" fill="none"/>'

/** Shown while the navigation is expanded: pressing it collapses the panel. */
export const panelLeftClose = `<svg ${ATTRS}>${FRAME}<path d="m16 15-3-3 3-3" fill="none"/></svg>`

/** Shown while the navigation is a rail: pressing it opens the panel. */
export const panelLeftOpen = `<svg ${ATTRS}>${FRAME}<path d="m14 9 3 3-3 3" fill="none"/></svg>`
