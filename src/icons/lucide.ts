/**
 * The navigation icons this app borrows from Lucide, as raw SVG rather than an
 * `@mdi/js` path.
 *
 * They are copied verbatim from what `@lucide/vue` renders, so that a control
 * which means the same thing here and in the DoCoNEXT apps built on that set is
 * not drawn twice differently. Copied rather than imported: it is a handful of
 * icons, and a second icon library for them would cost more than it explains.
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

/**
 * Settings, at the foot of the navigation. Lucide's `cog` — the toothed wheel
 * DoCoNEXT Core uses for the same entry, rather than MDI's `cog-outline`.
 */
export const cog = `<svg ${ATTRS}>`
  + '<path d="M11 10.27 7 3.34" fill="none"/>'
  + '<path d="m11 13.73-4 6.93" fill="none"/>'
  + '<path d="M12 22v-2" fill="none"/>'
  + '<path d="M12 2v2" fill="none"/>'
  + '<path d="M14 12h8" fill="none"/>'
  + '<path d="m17 20.66-1-1.73" fill="none"/>'
  + '<path d="m17 3.34-1 1.73" fill="none"/>'
  + '<path d="M2 12h2" fill="none"/>'
  + '<path d="m20.66 17-1.73-1" fill="none"/>'
  + '<path d="m20.66 7-1.73 1" fill="none"/>'
  + '<path d="m3.34 17 1.73-1" fill="none"/>'
  + '<path d="m3.34 7 1.73 1" fill="none"/>'
  + '<circle cx="12" cy="12" r="2" fill="none"/>'
  + '<circle cx="12" cy="12" r="8" fill="none"/>'
  + '</svg>'
