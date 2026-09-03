/**
 * The navigation icons this app borrows from Lucide, as raw SVG rather than an
 * `@mdi/js` path.
 *
 * They are copied verbatim from what `@lucide/vue` renders, so that the
 * navigation reads as one family with the DoCoNEXT apps built on that set
 * instead of mixing two drawing styles across the app switcher. Copied rather
 * than imported: it is a handful of icons, and a second icon library for them
 * would cost more than it explains.
 */
const ATTRS = 'xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"'
  + ' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"'

// `fill="none"` goes on every shape, not only on the <svg>: NcIconSvgWrapper's
// own stylesheet sets `fill: currentColor` on the element it is given, which
// the children would otherwise inherit — filling a stroke-drawn icon solid.
const path = (d: string): string => `<path d="${d}" fill="none"/>`
const circle = (cx: number, cy: number, r: number): string =>
  `<circle cx="${cx}" cy="${cy}" r="${r}" fill="none"/>`

const icon = (...shapes: string[]): string => `<svg ${ATTRS}>${shapes.join('')}</svg>`

const FRAME = [
  '<rect width="18" height="18" x="3" y="3" rx="2" fill="none"/>',
  path('M9 3v18'),
]

/** Shown while the navigation is expanded: pressing it collapses the panel. */
export const panelLeftClose = icon(...FRAME, path('m16 15-3-3 3-3'))

/** Shown while the navigation is a rail: pressing it opens the panel. */
export const panelLeftOpen = icon(...FRAME, path('m14 9 3 3-3 3'))

/** Search. */
export const search = icon(
  path('m21 21-4.34-4.34'),
  circle(11, 11, 8),
)

/**
 * Favorites. Lucide draws its star as an outline where MDI fills it — the
 * navigation names a page, it does not report that anything is starred, so
 * the outline is the one that belongs in a row of outlined icons.
 */
export const star = icon(
  path('M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1'
    + ' .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122'
    + ' 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16'
    + ' 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z'),
)

/** Saved and recent searches: a list, read by a magnifier. */
export const textSearch = icon(
  path('M21 5H3'),
  path('M10 12H3'),
  path('M10 19H3'),
  circle(17, 15, 3),
  path('m21 19-1.9-1.9'),
)

/** Settings, at the foot of the navigation. */
export const cog = icon(
  path('M11 10.27 7 3.34'),
  path('m11 13.73-4 6.93'),
  path('M12 22v-2'),
  path('M12 2v2'),
  path('M14 12h8'),
  path('m17 20.66-1-1.73'),
  path('m17 3.34-1 1.73'),
  path('M2 12h2'),
  path('m20.66 17-1.73-1'),
  path('m20.66 7-1.73 1'),
  path('m3.34 17 1.73-1'),
  path('m3.34 7 1.73 1'),
  circle(12, 12, 2),
  circle(12, 12, 8),
)
