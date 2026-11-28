"use client";

import { useEffect } from "react";
import { useTheme } from "next-themes";

const LIGHT_BACKGROUND = "#ffffff";
const DARK_BACKGROUND = "#0f172a";
const SYNC_ATTRIBUTE = "data-ethr-theme-color";

/**
 * Keeps the browser chrome's colour in step with the theme picker.
 *
 * The viewport metadata can only follow the operating system's colour scheme,
 * so a reader who picks Dark on a light phone got a light status bar over a dark
 * page. This writes one `theme-color` meta, first in `<head>` and without a
 * media query, from the resolved theme. The page background is the colour used
 * so the bar and the page read as one surface; high contrast reads it from the
 * body because that theme repaints its own surface.
 */
export function ThemeColorSync() {
  const { resolvedTheme } = useTheme();

  useEffect(() => {
    if (!resolvedTheme) return;

    let color = LIGHT_BACKGROUND;
    if (resolvedTheme === "dark") {
      color = DARK_BACKGROUND;
    } else if (resolvedTheme === "high-contrast") {
      const background = getComputedStyle(document.body).backgroundColor;
      if (background && background !== "rgba(0, 0, 0, 0)") color = background;
    }

    let meta = document.head.querySelector<HTMLMetaElement>(
      `meta[${SYNC_ATTRIBUTE}]`,
    );
    if (!meta) {
      meta = document.createElement("meta");
      meta.name = "theme-color";
      meta.setAttribute(SYNC_ATTRIBUTE, "");
      document.head.prepend(meta);
    }
    meta.content = color;
  }, [resolvedTheme]);

  return null;
}
