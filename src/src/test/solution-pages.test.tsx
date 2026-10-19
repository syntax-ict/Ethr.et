import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import { RouteLocaleProvider } from "@/lib/i18n/route-locale";
import { SolutionPage } from "@/components/marketing/solution-page";
import { SOLUTIONS } from "@/components/marketing/solutions";
import { PUBLIC_ROUTES } from "@/lib/site-url";
import { marketingMetadata } from "@/app/(marketing)/[locale]/page-metadata";
import en from "@/lib/i18n/locales/en.json";
import am from "@/lib/i18n/locales/am.json";

const EN = en as Record<string, string>;
const AM = am as Record<string, string>;

/**
 * The search-intent pages (2026-10-19). Each exists to rank for one thing
 * people search for, so what is pinned is what a crawler reads: one keyword
 * H1, a translated head, a route in the sitemap, and copy in both languages.
 */
describe("search-intent pages", () => {
  it.each(SOLUTIONS.map((s) => [s.id, s] as const))(
    "%s is a public route with copy in both languages",
    (id, solution) => {
      expect(PUBLIC_ROUTES).toContain(solution.path);

      const keys = [
        `marketing.meta.${id}.title`,
        `marketing.meta.${id}.description`,
        ...["overline", "title", "subtitle", "points_title", "link"].map(
          (k) => `marketing.solutions.${id}.${k}`,
        ),
        ...solution.points.flatMap((p) => [
          `marketing.solutions.${id}.${p}`,
          `marketing.solutions.${id}.${p}_desc`,
        ]),
      ];
      for (const key of keys) {
        expect(EN[key], key).toBeTruthy();
        expect(AM[key], key).toMatch(/[ሀ-፿]/);
      }
    },
  );

  it.each(SOLUTIONS.map((s) => [s.id] as const))(
    "%s renders one H1, every point, and links to the other three",
    (id) => {
      render(
        <RouteLocaleProvider locale="en">
          <SolutionPage id={id} />
        </RouteLocaleProvider>,
      );

      const h1 = screen.getAllByRole("heading", { level: 1 });
      expect(h1).toHaveLength(1);
      expect(h1[0]).toHaveTextContent(EN[`marketing.solutions.${id}.title`]!);

      const solution = SOLUTIONS.find((s) => s.id === id)!;
      for (const point of solution.points) {
        expect(
          screen.getByText(EN[`marketing.solutions.${id}.${point}`]!),
        ).toBeInTheDocument();
      }

      const hrefs = screen
        .getAllByRole("link")
        .map((a) => a.getAttribute("href"));
      for (const other of SOLUTIONS.filter((s) => s.id !== id)) {
        expect(hrefs).toContain(`/en${other.path}`);
      }
      expect(hrefs).toContain("/register");
      expect(hrefs).not.toContain(`/en${solution.path}`);
    },
  );

  it("gives each page its own canonical and an English x-default", () => {
    for (const { id, path } of SOLUTIONS) {
      const meta = marketingMetadata("am", path, id, {
        title: "x",
        description: "x",
      });
      expect(meta.alternates?.canonical).toBe(`/am${path}`);
      expect(meta.alternates?.languages).toMatchObject({
        "x-default": `/en${path}`,
      });
      expect(meta.title).toBe(AM[`marketing.meta.${id}.title`]);
    }
  });

  it("claims no device brand and no biometric hardware", () => {
    // The capability audit (2026-10-17): ETHR names no terminal vendor and does
    // not sell fingerprint or face hardware. A page written to rank is where
    // that claim would be most tempting, so it is pinned here.
    const copy = Object.entries({ ...EN })
      .filter(([k]) => k.startsWith("marketing.solutions."))
      .map(([, v]) => v)
      .join(" ");
    expect(copy).not.toMatch(/zkteco|hikvision|suprema|biometric|fingerprint/i);
  });
});
