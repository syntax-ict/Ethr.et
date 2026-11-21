import { readFileSync } from "node:fs";
import { join } from "node:path";

import { describe, expect, it } from "vitest";

/**
 * Four facts about `styles/globals.css` that nothing else would notice losing.
 *
 * - `dark:` must follow the `.dark` class next-themes writes, not the OS
 *   setting; Tailwind v4 defaults to the media query, so a reader who picked
 *   Dark on a light phone got a mix of both.
 * - The overlay classes (`animate-in`, `fade-in-0`, `zoom-in-95`,
 *   `slide-in-from-*`) come from tw-animate-css; without the import they are
 *   dead and every dialog, sheet and menu just appears.
 * - Reduced motion has to cancel delays as well as durations.
 * - Anchored sections stop below the fixed marketing header.
 */
const css = readFileSync(
  join(__dirname, "..", "styles", "globals.css"),
  "utf8",
);

describe("globals.css contract", () => {
  it("binds dark: to the .dark class", () => {
    expect(css).toContain("@custom-variant dark");
    expect(css).toMatch(
      /@custom-variant dark \(&:where\(\.dark, \.dark \*\)\)/,
    );
  });

  it("imports the overlay animation utilities", () => {
    expect(css).toContain('@import "tw-animate-css"');
  });

  it("cancels animation delay inside the reduced-motion block", () => {
    const start = css.indexOf("@media (prefers-reduced-motion: reduce)");
    expect(start).toBeGreaterThan(-1);
    const block = css.slice(start, css.indexOf("\n}\n", start));
    expect(block).toContain("animation-delay: 0ms");
  });

  it("offsets anchored scrolling below the fixed header", () => {
    expect(css).toContain("scroll-padding-top");
  });
});
