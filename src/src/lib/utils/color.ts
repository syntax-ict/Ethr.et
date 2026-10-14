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

function toHex(rgb: [number, number, number]): string {
  return (
    "#" +
    rgb
      .map((v) => Math.min(255, Math.max(0, Math.round(v))))
      .map((v) => v.toString(16).padStart(2, "0"))
      .join("")
  );
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

/** WCAG 2.2 AA for text of ordinary size. */
export const AA_CONTRAST = 4.5;

/** White or near-black, whichever reads better on `background`. */
export function readableInkOn(background: string): string {
  return contrastRatio(INK_LIGHT, background) >=
    contrastRatio(INK_DARK, background)
    ? INK_LIGHT
    : INK_DARK;
}

/**
 * A brand colour as a surface for text, moved only as far as AA needs: the
 * ink is whichever reads better on it, and while that ink falls short of
 * 4.5:1 the colour steps toward the ink's opposite (darker under white,
 * lighter under near-black). A colour that already reads is returned as it
 * is, with `changed` false, so the settings screen can say when it moved.
 */
export function aaCorrected(
  hex: string,
): { color: string; ink: string; changed: boolean } | null {
  const rgb = parseHex(hex);
  if (!rgb) return null;
  const original = toHex(rgb);
  const ink = readableInkOn(original);
  const toward = ink === INK_LIGHT ? 0 : 255;

  let current = rgb;
  for (
    let step = 0;
    step < 60 && contrastRatio(ink, toHex(current)) < AA_CONTRAST;
    step++
  ) {
    current = current.map((v) => v + (toward - v) * 0.06) as [
      number,
      number,
      number,
    ];
  }

  const color = toHex(current);
  return { color, ink, changed: color !== original };
}

/** Mix a colour toward white by `amount` (0..1), for a hover tone. */
export function tint(hex: string, amount: number): string | null {
  const rgb = parseHex(hex);
  if (!rgb) return null;
  return toHex(
    rgb.map((v) => v + (255 - v) * amount) as [number, number, number],
  );
}
