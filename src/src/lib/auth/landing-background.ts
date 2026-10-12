import type { CSSProperties } from "react";

/** The background fields of an organisation's sign-in page. */
export interface LandingBackground {
  background: "brand" | "gradient" | "image";
  background_image_url: string | null;
}

/**
 * The panel's background as the organisation chose it: its brand colour (the
 * class default), a gradient into its secondary colour, or its own photo under
 * a dark wash so the text stays readable. The image URL is https-only on the
 * server, and JSON-quoted here so it cannot close the `url()`.
 */
export function panelBackground(
  landing: LandingBackground | null | undefined,
  secondary: string | null | undefined,
): CSSProperties | undefined {
  if (landing?.background === "gradient") {
    const to =
      secondary ?? "color-mix(in oklab, var(--color-primary) 55%, #000)";
    return {
      backgroundImage: `linear-gradient(135deg, var(--color-primary) 0%, ${to} 100%)`,
    };
  }
  if (landing?.background === "image" && landing.background_image_url) {
    return {
      backgroundImage: `linear-gradient(180deg, rgba(0,0,0,0.45), rgba(0,0,0,0.7)), url(${JSON.stringify(landing.background_image_url)})`,
      backgroundSize: "cover",
      backgroundPosition: "center",
      // Over a photo the ink is white, whatever the brand colour.
      ["--color-primary-foreground" as string]: "#ffffff",
    };
  }
  return undefined;
}
