import { render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it } from "vitest";
import am from "@/lib/i18n/locales/am.json";
import { registerLocale } from "@/lib/i18n/translations";
import { NotFoundContent, localeFromPath } from "@/app/not-found-content";

/**
 * One exported 404 answers every address. Production QA 2026-11-13 found
 * `/am/no-such-page` served in Amharic and switched to English after
 * hydration, because the reader had saved English: the address names the
 * language and must win, as on every marketing page.
 */
const AM_TITLE = (am as Record<string, string>)["not_found.title"];

function at(path: string) {
  window.history.replaceState(null, "", path);
}

afterEach(() => {
  localStorage.clear();
  at("/");
});

describe("localeFromPath", () => {
  it("reads a shipped language from the first segment only", () => {
    expect(localeFromPath("/am/no-such-page")).toBe("am");
    expect(localeFromPath("/en/pricing/x")).toBe("en");
    expect(localeFromPath("/om/x")).toBe("om");
    expect(localeFromPath("/no-such-page")).toBeNull();
    expect(localeFromPath("/ti/x")).toBeNull();
    expect(localeFromPath("/")).toBeNull();
  });
});

describe("the 404 page's language", () => {
  it("follows the address over a saved choice", () => {
    registerLocale("am", { "not_found.title": AM_TITLE });
    localStorage.setItem("locale", "en");
    at("/am/no-such-page");

    render(<NotFoundContent />);

    expect(screen.getByText(AM_TITLE)).toBeInTheDocument();
    expect(screen.queryByText("Page not found")).not.toBeInTheDocument();
  });

  it("reads English on an English address", () => {
    localStorage.setItem("locale", "am");
    at("/en/no-such-page");

    render(<NotFoundContent />);

    expect(screen.getByText("Page not found")).toBeInTheDocument();
  });

  it("keeps the saved choice where the address names no language", () => {
    localStorage.setItem("locale", "en");
    at("/no-such-page");

    render(<NotFoundContent />);

    expect(screen.getByText("Page not found")).toBeInTheDocument();
  });
});
