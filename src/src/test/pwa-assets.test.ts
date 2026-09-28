import { readFileSync } from "node:fs";
import { existsSync } from "node:fs";
import { join } from "node:path";

import { describe, expect, it } from "vitest";

/**
 * Every icon the PWA references must exist on disk.
 *
 * WHY. `public/manifest.json` and `public/sw.js` both referenced
 * `/icons/badge-72.png` and the file had never been created, so push-notification
 * badges were broken app-wide. `docs/audit/BASELINE.md` listed it among three
 * findings left open deliberately — left because it is service-worker behaviour
 * rather than public-site behaviour, not because anyone judged it acceptable.
 *
 * Nothing could have caught it. A missing icon is not a type error, not a lint
 * error, not a broken markdown link, and not a failing request in any test: the
 * service worker asks the browser for it at push time, on a device, in
 * production. It 404s there and the notification renders with no badge — which
 * looks like a design choice rather than a defect.
 *
 * WHAT THIS ASSERTS. Every `/icons/...` path mentioned by either file resolves to
 * a real file under `public/`. Deliberately a sweep over whatever the files
 * reference rather than a list of known icons: the next asset added to the
 * manifest is covered the day it is added, which a hardcoded list would not be.
 */
const PUBLIC_DIR = join(__dirname, "..", "..", "public");

function iconReferencesIn(relativePath: string): string[] {
  const source = readFileSync(join(PUBLIC_DIR, relativePath), "utf8");

  // Matches "/icons/badge-72.png" in JSON values and in JS string literals alike.
  const matches = source.matchAll(
    /\/icons\/[A-Za-z0-9._-]+\.(?:png|svg|ico|webp)/g,
  );

  return [...new Set([...matches].map((m) => m[0]))];
}

describe("PWA assets", () => {
  it("references at least one icon from each file, so an empty sweep cannot pass", () => {
    // Without this, a regex that silently stops matching turns both tests below
    // into vacuous truths — the failure mode that let `vendor/bin/pest` collect
    // 21 of 132 classes and exit green.
    expect(iconReferencesIn("manifest.json").length).toBeGreaterThan(0);
    expect(iconReferencesIn("sw.js").length).toBeGreaterThan(0);
  });

  it.each([["manifest.json"], ["sw.js"]])(
    "every icon %s references exists on disk",
    (file) => {
      const missing = iconReferencesIn(file).filter(
        (icon) => !existsSync(join(PUBLIC_DIR, icon.replace(/^\//, ""))),
      );

      expect(
        missing,
        `${file} references icons that do not exist: ${missing.join(", ")}`,
      ).toEqual([]);
    },
  );

  it("ships a monochrome badge, which cannot be the colour app icon", () => {
    const manifest = JSON.parse(
      readFileSync(join(PUBLIC_DIR, "manifest.json"), "utf8"),
    ) as {
      icons: { src: string; purpose?: string }[];
    };

    const monochrome = manifest.icons.filter((i) =>
      i.purpose?.split(/\s+/).includes("monochrome"),
    );

    expect(
      monochrome.length,
      "manifest declares no monochrome icon for notification badges",
    ).toBe(1);

    // Android renders a badge as an alpha mask: it discards colour and fills every
    // opaque pixel with a system accent. Pointing this entry at one of the filled
    // rounded-square app icons would render as a solid blob at ~24px, which is why
    // the fix was to generate a glyph rather than to repoint the reference at an
    // icon that happens to exist.
    expect(monochrome[0].src).not.toMatch(/\/icons\/icon-\d+\.png$/);
  });
});
