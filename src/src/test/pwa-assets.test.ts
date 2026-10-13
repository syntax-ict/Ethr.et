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

  // Matches "/app-icons/badge-72.png" in JSON values and in JS string literals
  // alike — and the old "/icons/…" too, so a reference that drifts back there is
  // swept up and reported by the test below rather than skipped.
  const matches = source.matchAll(
    /\/(?:app-)?icons\/[A-Za-z0-9._-]+\.(?:png|svg|ico|webp)/g,
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

  it("serves nothing under /icons/, which the host's Apache keeps for itself", () => {
    // Production QA 2026-10-11, P10: every /icons/* request answered 404 in
    // production although the files were deployed. The host's stock
    // `Alias /icons/ …` (Apache's autoindex icon set) takes the path before the
    // document root does — /icons/blank.gif answered 200 image/gif. A server
    // alias cannot be changed from a shared-hosting subscription, so the app's
    // icons live at /app-icons/ and nothing may point back.
    expect(existsSync(join(PUBLIC_DIR, "icons"))).toBe(false);
    for (const file of ["manifest.json", "sw.js"]) {
      expect(
        iconReferencesIn(file).filter((ref) => ref.startsWith("/icons/")),
        `${file} references the shadowed /icons/ path`,
      ).toEqual([]);
    }
  });

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

  it("has exactly one manifest, because the second one was a trap", () => {
    // There were two. `public/manifest.json` is the live one — `root-shell.tsx`
    // sets `manifest: "/manifest.json"` and the built HTML carries
    // `<link rel="manifest" href="/manifest.json">`. `app/manifest.ts` generated
    // `/manifest.webmanifest`, which nothing referenced.
    //
    // It was not inert. In the App Router, Next auto-links a generated manifest
    // when no explicit one is set — so deleting one line from `root-shell.tsx`
    // would have silently promoted it, and it declared `theme_color: "#2563eb"`
    // (stock Tailwind blue, not ETHR Primary `#0F4C75`), no shortcuts, and no
    // monochrome badge. A dead file that goes live on an unrelated edit is worse
    // than no file, which is why it was deleted rather than reconciled.
    expect(
      existsSync(join(__dirname, "..", "app", "manifest.ts")),
      "app/manifest.ts is back. Two manifests drift, and the generated one wins " +
        "automatically if the explicit `manifest:` in root-shell.tsx is ever removed.",
    ).toBe(false);

    const manifest = JSON.parse(
      readFileSync(join(PUBLIC_DIR, "manifest.json"), "utf8"),
    ) as { theme_color?: string };

    // Pinned because the deleted file disagreed on exactly this, and the
    // disagreement is invisible until someone installs the app.
    expect(manifest.theme_color?.toUpperCase()).toBe("#0F4C75");
  });

  it("declares maskable icons on their own, as full-bleed images", () => {
    // The 192/384/512 icons were declared "maskable any". Those files have
    // transparent rounded corners, so Android's mask showed white corners
    // around the launcher icon; combined purposes are discouraged for that
    // reason. Maskable icons are separate files, brand colour edge to edge.
    const manifest = JSON.parse(
      readFileSync(join(PUBLIC_DIR, "manifest.json"), "utf8"),
    ) as { icons: { src: string; purpose?: string }[] };

    for (const icon of manifest.icons) {
      expect(
        (icon.purpose ?? "any").split(/\s+/),
        `${icon.src} mixes purposes`,
      ).toHaveLength(1);
    }

    const maskable = manifest.icons.filter((i) => i.purpose === "maskable");
    expect(maskable.map((i) => i.src)).toEqual([
      "/app-icons/maskable-192.png",
      "/app-icons/maskable-512.png",
    ]);
  });

  it("keeps the app's identity stable across releases", () => {
    // Browsers key an installed app on `id`, falling back to start_url. The
    // start_url gained a query string; without an explicit id every phone
    // would see a different app and offer to install it again.
    const manifest = JSON.parse(
      readFileSync(join(PUBLIC_DIR, "manifest.json"), "utf8"),
    ) as { id?: string; start_url: string; scope?: string };

    expect(manifest.id).toBe("/dashboard");
    expect(manifest.scope).toBe("/");
    expect(manifest.start_url.startsWith("/dashboard")).toBe(true);
  });
});
