import { describe, it, expect, vi } from "vitest";
import { readdirSync, readFileSync, statSync } from "node:fs";
import { join, relative } from "node:path";
import { render, screen } from "@testing-library/react";
import { renderToString } from "react-dom/server";

import NotFound from "@/app/not-found";
import DashboardLayout from "@/app/(dashboard)/layout";
import { DashboardShell } from "@/app/(dashboard)/dashboard-shell";
import { AppLoadingScreen } from "@/components/shared/app-loading-screen";
import am from "@/lib/i18n/locales/am.json";

// What lies past the dictionary gate is not this file's business, and the root
// shell loads `next/font`, which only runs under Next.
vi.mock("@/components/shared/auth-guard", () => ({
  AuthGuard: () => <p>past the dictionary</p>,
}));
vi.mock("@/app/root-shell", () => ({
  RootShell: ({ children }: { children: React.ReactNode }) => children,
  baseMetadata: {},
  baseViewport: {},
}));

/**
 * The whole Amharic dictionary belongs to three app shells only.
 *
 * `am.json` is the largest file the browser can be sent: 514 KB, 89 KB of
 * brotli. The sign-in, kiosk and offline shells prerender real forms in
 * Amharic and register all of it through `AmharicDictionary`; the dashboard
 * prerenders only a loading screen and loads the reader's own language
 * (2026-10-31); a public page gets a projection from its server layout
 * (`public-dictionary.ts`). One more client importer anywhere outside
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
  it("is registered whole by the shells that prerender forms, and nothing else", () => {
    expect(importersOf(/amharic-dictionary/)).toEqual([
      "app/(auth)/layout.tsx",
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

describe("the dashboard shell", () => {
  it("gets its one prerendered string in Amharic from the server", () => {
    const shell = DashboardLayout({ children: null }).props.children;

    expect(shell.type).toBe(DashboardShell);
    expect(shell.props.loadingLabel).toBe(am["common.loading"]);
  });

  it("renders first exactly the loading screen the prerendered HTML holds", () => {
    // The server render, which hydration must match: nothing past the gate.
    const html = renderToString(
      <DashboardShell loadingLabel={am["common.loading"]}>
        <p>a page</p>
      </DashboardShell>,
    );

    expect(html).toBe(
      renderToString(<AppLoadingScreen label={am["common.loading"]} />),
    );
  });

  it("opens once the reader's dictionary is in memory", async () => {
    render(
      <DashboardShell loadingLabel={am["common.loading"]}>
        <p>a page</p>
      </DashboardShell>,
    );

    expect(screen.getByText(am["common.loading"])).toBeInTheDocument();
    expect(await screen.findByText("past the dictionary")).toBeInTheDocument();
    expect(screen.queryByText(am["common.loading"])).not.toBeInTheDocument();
  });
});
