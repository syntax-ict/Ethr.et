import { readFileSync } from "node:fs";
import { join } from "node:path";

import { describe, expect, it } from "vitest";

/**
 * The text tokens must clear WCAG AA against the surfaces they are used on.
 *
 * WHY. `docs/audit/BASELINE.md` recorded `--text-secondary` as `#6c7b91`, which is
 * **4.30:1** on white — under AA's 4.5:1 for normal-size text, so every
 * `text-sm text-muted-foreground` on a white surface was marginally
 * non-compliant. It was left open deliberately as "a Phase 8 decision", because
 * fixing it meant darkening the token and repainting the product.
 *
 * Measured again on 2026-09-28, the token is `#64748b` — **4.76:1**, which passes.
 * The finding was stale: it had already been fixed, and the audit entry outlived
 * the defect. That is the reason this file exists rather than a doc edit. A
 * measurement recorded in prose goes stale silently; a measurement that runs does
 * not.
 *
 * WHAT MAKES IT WORTH PINNING. `--text-secondary` on `--surface-secondary` is
 * **4.55:1** — six hundredths above the threshold. A barely-perceptible darkening
 * of that surface, or lightening of that text, drops it under AA with no visible
 * change to review against. Lighthouse flags this band intermittently, which is
 * what a near-threshold ratio looks like and is precisely what a human reviewer
 * learns to ignore.
 *
 * WHAT IT DOES NOT CLAIM. It checks the token pairs named below, not every
 * foreground/background combination the product renders, and not text over
 * images or gradients. AA for large text (3:1) is not asserted, so a pair used
 * only at >=24px is held to the stricter bar here — deliberately, since a token
 * is not sized.
 */
const CSS = readFileSync(
  join(__dirname, "..", "styles", "globals.css"),
  "utf8",
);

/**
 * Read a token from a specific block, because the file declares the same names
 * three times over — light, dark, and a high-contrast override. A file-wide
 * regex would return whichever came first and silently test the wrong theme.
 */
function token(name: string, afterMarker: string): string {
  const start = CSS.indexOf(afterMarker);

  expect(
    start,
    `globals.css no longer contains the marker "${afterMarker}"`,
  ).toBeGreaterThan(-1);

  const match = new RegExp(`--${name}:\\s*(#[0-9a-fA-F]{6})`).exec(
    CSS.slice(start),
  );

  expect(match, `--${name} not found after "${afterMarker}"`).not.toBeNull();

  return match![1];
}

function channel(value: number): number {
  const c = value / 255;

  return c <= 0.04045 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
}

function luminance(hex: string): number {
  const n = parseInt(hex.slice(1), 16);

  return (
    0.2126 * channel((n >> 16) & 255) +
    0.7152 * channel((n >> 8) & 255) +
    0.0722 * channel(n & 255)
  );
}

function contrast(a: string, b: string): number {
  const [x, y] = [luminance(a), luminance(b)];

  return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05);
}

const LIGHT = "/* ETHR brand palette (CLAUDE.md Visual Identity) */";

describe("design token contrast", () => {
  it("computes a known ratio correctly, so the maths itself is not assumed", () => {
    // Black on white is exactly 21:1, and the value the audit recorded for the
    // old token is 4.30 — if either drifts, every assertion below is meaningless.
    expect(contrast("#000000", "#ffffff")).toBeCloseTo(21, 5);
    expect(contrast("#6c7b91", "#ffffff")).toBeCloseTo(4.3, 1);
  });

  it.each([
    ["text-secondary", "surface-primary"],
    ["text-secondary", "surface-secondary"],
    ["text-primary", "surface-primary"],
    ["text-primary", "surface-secondary"],
  ])("light theme: --%s on --%s clears AA", (fg, bg) => {
    const ratio = contrast(token(fg, LIGHT), token(bg, LIGHT));

    expect(
      ratio,
      `--${fg} on --${bg} is ${ratio.toFixed(2)}:1, under the 4.5:1 AA floor for normal text`,
    ).toBeGreaterThanOrEqual(4.5);
  });

  it("the sidebar's own text clears AA against the sidebar surface", () => {
    // Its own pair: the sidebar is #0a2e4a in both themes, so a token change made
    // for the page body can break it without touching anything that looks related.
    const ratio = contrast(
      token("text-on-sidebar", LIGHT),
      token("surface-sidebar", LIGHT),
    );

    expect(
      ratio,
      `--text-on-sidebar on --surface-sidebar is ${ratio.toFixed(2)}:1`,
    ).toBeGreaterThanOrEqual(4.5);
  });
});
