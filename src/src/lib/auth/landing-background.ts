import type { CSSProperties } from "react";

/** The background field of an organisation's sign-in page. */
export interface LandingBackground {
  background: "brand" | "gradient" | "pattern" | "image";
}

/**
 * The organisation's uploaded cover, as the server presents it: the image,
 * the widths it was encoded at, and the point to keep in frame.
 */
export interface LandingCover {
  url: string | null;
  srcset: string;
  width: number | string;
  height: number | string;
  focal_x: number;
  focal_y: number;
}

/** The focal point as a CSS position, for `object-position` or `background-position`. */
export function coverPosition(cover: LandingCover): string {
  const pct = (v: number) => Math.round(Math.min(1, Math.max(0, v)) * 100);
  return `${pct(cover.focal_x)}% ${pct(cover.focal_y)}%`;
}

/**
 * The panel's background as the organisation chose it: its brand colour (the
 * class default), a gradient into its secondary colour, a quiet dot pattern
 * over the brand colour, or its uploaded cover under a dark wash so the text
 * stays readable. The cover URL is the server's own, and JSON-quoted here so
 * it cannot close the `url()`.
 */
export function panelBackground(
  landing: LandingBackground | null | undefined,
  secondary: string | null | undefined,
  cover?: LandingCover | null,
): CSSProperties | undefined {
  if (landing?.background === "gradient") {
    const to =
      secondary ?? "color-mix(in oklab, var(--color-primary) 55%, #000)";
    return {
      backgroundImage: `linear-gradient(135deg, var(--color-primary) 0%, ${to} 100%)`,
    };
  }
  if (landing?.background === "pattern") {
    return {
      backgroundImage:
        "radial-gradient(circle at 1px 1px, rgba(255,255,255,0.16) 1.5px, transparent 0)",
      backgroundSize: "22px 22px",
    };
  }
  if (landing?.background === "image" && cover?.url) {
    return {
      backgroundImage: `linear-gradient(180deg, rgba(0,0,0,0.45), rgba(0,0,0,0.7)), url(${JSON.stringify(cover.url)})`,
      backgroundSize: "cover",
      backgroundPosition: coverPosition(cover),
      // Over a photo the ink is white, whatever the brand colour.
      ["--color-primary-foreground" as string]: "#ffffff",
    };
  }
  return undefined;
}
