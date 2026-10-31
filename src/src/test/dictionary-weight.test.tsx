import { describe, it, expect } from "vitest";
import { readdirSync, readFileSync, statSync } from "node:fs";
import { join, relative } from "node:path";
import { render, screen } from "@testing-library/react";

import NotFound from "@/app/not-found";
import am from "@/lib/i18n/locales/am.json";

/**
 * The whole Amharic dictionary belongs to the app shells only.
 *
 * `am.json` is the largest file the browser can be sent: 514 KB, 89 KB of
 * brotli. The app shells need all of it on their first render and register it
 * through `AmharicDictionary`; a public page gets a projection from its server
 * layout (`public-dictionary.ts`). One more client importer anywhere outside
 * the shells puts the file on the pages that importer reaches, and nothing
 * would show it: the root `not-found.tsx` did, and since that page's code
 * ships with every route, every visitor to the English public site downloaded
 * the Amharic dictionary first (#385 to 2026-10-31).
 */

const SRC = join(__dirname, "..");

function sources(dir: string): string[] {
  return readdirSync(dir).flatMap((name) => {
    const path = join(dir, name);
    if (statSync(path).isDirectory())
      return name === "test" ? [] : sources(path);
    return /\.(ts|tsx)$/.test(name) ? [path] : [];
  });
}

function importersOf(pattern: RegExp): string[] {
  return sources(SRC)
    .filter((path) =>
      new RegExp(
        `^import\\s[^;]*from\\s+["'][^"']*${pattern.source}["']`,
        "m",
      ).test(readFileSync(path, "utf8")),
    )
    .map((path) => relative(SRC, path).replace(/\\/g, "/"))
    .sort();
}

describe("the Amharic dictionary", () => {
  it("is registered whole by the app shells and nothing else", () => {
    expect(importersOf(/amharic-dictionary/)).toEqual([
      "app/(auth)/layout.tsx",
      "app/(dashboard)/layout.tsx",
      "app/kiosk/layout.tsx",
      "app/offline/layout.tsx",
    ]);
  });

  it("is imported statically only where it stays on the server or is the shells' own", () => {
    // `translations.ts` loads each locale with a dynamic import, which is a
    // separate chunk fetched on demand and is not matched here.
    expect(importersOf(/locales\/\w+\.json/)).toEqual([
      "lib/i18n/amharic-dictionary.tsx",
      "lib/i18n/public-dictionary.ts",
    ]);
    // It imports every locale whole, so it must never become client code.
    for (const path of importersOf(/public-dictionary/)) {
      expect(readFileSync(join(SRC, path), "utf8"), path).not.toMatch(
        /^["']use client["']/m,
      );
    }
  });
});

describe("the 404 page", () => {
  it("hands the browser its own strings in Amharic, and only those", () => {
    // Asserted on the element rather than the render: the test setup registers
    // every dictionary whole, so a render would pass with none handed over.
    const page = NotFound();

    expect(page.props.locale).toBe("am");
    expect(page.props.dictionary).toEqual({
      "not_found.back_home": am["not_found.back_home"],
      "not_found.description": am["not_found.description"],
      "not_found.go_dashboard": am["not_found.go_dashboard"],
      "not_found.title": am["not_found.title"],
    });
  });

  it("still renders as a page", () => {
    localStorage.setItem("locale", "am");
    render(<NotFound />);

    expect(screen.getByText(am["not_found.title"])).toBeInTheDocument();
  });

  it("is a server component, so its dictionary import stays on the server", () => {
    const page = readFileSync(join(SRC, "app/not-found.tsx"), "utf8");

    expect(page).not.toMatch(/^["']use client["']/m);
  });
});
