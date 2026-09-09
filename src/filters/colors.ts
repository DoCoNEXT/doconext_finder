/**
 * Reading a colour well enough to write text on it.
 *
 * Small and hand-rolled rather than a dependency: this is one formula from
 * WCAG, and the frontend already carries enough weight per page.
 */

/** Six-digit hex, the only shape the server stores a picked colour in. */
const HEX = /^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i

/**
 * Relative luminance, per WCAG 2.1 — the perceived lightness of a colour, where
 * 0 is black and 1 is white. Green weighs most because the eye reads it that
 * way, which is why a plain average of the channels calls yellow dark.
 * @param hex a `#rrggbb` colour
 */
export function luminance(hex: string): number {
  const match = HEX.exec(hex.trim())
  if (!match) {
    return 1
  }

  const [r, g, b] = match.slice(1, 4).map((pair) => {
    const channel = parseInt(pair, 16) / 255

    return channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4
  }) as [number, number, number]

  return 0.2126 * r + 0.7152 * g + 0.0722 * b
}

/**
 * Black or white, whichever is easier to read on the given background.
 *
 * The threshold is where the contrast ratio against black and against white
 * cross — 0.179 by the WCAG formula, not the 0.5 a linear reading suggests —
 * so a mid-yellow correctly gets black text rather than white.
 * @param background a `#rrggbb` colour
 */
export function readableTextOn(background: string): string {
  return luminance(background) > 0.179 ? '#000000' : '#ffffff'
}
