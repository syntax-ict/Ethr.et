/**
 * Colour helpers for tenant branding, whose colours are any hex a tenant
 * picks — so nothing about their contrast can be assumed.
 */

const HEX = /^#?([0-9a-f]{6})$/i;

export function parseHex(hex: string): [number, number, number] | null {
  const m = hex.trim().match(HEX);
  if (!m) return null;
  return [0, 2, 4].map((i) => parseInt(m[1].slice(i, i + 2), 16)) as [
    number,
    number,
    number,
  ];
}

/** WCAG 2.x relative luminance, 0 (black) to 1 (white). */
export function relativeLuminance([r, g, b]: [number, number, number]): number {
  const channel = (v: number) => {
    const c = v / 255;
    return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
  };
  return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b);
}

export function contrastRatio(a: string, b: string): number {
  const la = relativeLuminance(parseHex(a) ?? [0, 0, 0]);
  const lb = relativeLuminance(parseHex(b) ?? [0, 0, 0]);
  const [hi, lo] = la > lb ? [la, lb] : [lb, la];
  return (hi + 0.05) / (lo + 0.05);
}

export const INK_LIGHT = "#ffffff";
export const INK_DARK = "#0f172a";

/** White or near-black, whichever reads better on `background`. */
export function readableInkOn(background: string): string {
  return contrastRatio(INK_LIGHT, background) >=
    contrastRatio(INK_DARK, background)
    ? INK_LIGHT
    : INK_DARK;
}

/** Mix a colour toward white by `amount` (0..1), for a hover tone. */
export function tint(hex: string, amount: number): string | null {
  const rgb = parseHex(hex);
  if (!rgb) return null;
  return (
    "#" +
    rgb
      .map((v) => Math.round(v + (255 - v) * amount))
      .map((v) => v.toString(16).padStart(2, "0"))
      .join("")
  );
}
