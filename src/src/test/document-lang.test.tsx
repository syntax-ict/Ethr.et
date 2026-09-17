import { describe, it, expect, beforeEach, afterEach } from "vitest";
import { render } from "@testing-library/react";
import { DEFAULT_LOCALE, setLocale } from "@/lib/i18n/translations";
import { RouteLocaleProvider } from "@/lib/i18n/route-locale";
import { useT } from "@/lib/i18n/useT";

/**
 * `<html lang>` must name the language the page is actually in.
 *
 * The root layout hardcoded `lang="en"` while `DEFAULT_LOCALE` is `am`, so the
 * server emitted Amharic inside an element declaring English — confirmed
 * against a real build: `.next/server/app/index.html` carried
 * `<html lang="en">` around `<h1>ለኢትዮጵያ ድርጅቶች ሙሉ የሰው ሃብት መድረክ</h1>`.
 *
 * Not cosmetic. `lang` chooses a screen reader's pronunciation rules, so
 * Amharic was read aloud as English; it tells search engines what language the
 * page is in; and it drives hyphenation, font fallback and spellchecking.
 *
 * No test could see this before, because none rendered anything at the document
 * level in a non-English locale — the gap `LANDING_PAGE_PRODUCTION_PLAN.md`
 * records as F1.
 */
function Probe() {
  const { locale } = useT();
  return <span data-testid="locale">{locale}</span>;
}

describe("document language", () => {
  beforeEach(() => {
    localStorage.clear();
    document.documentElement.lang = DEFAULT_LOCALE;
  });

  afterEach(() => {
    localStorage.setItem("locale", "en");
  });

  it("defaults to the locale the server actually renders", () => {
    // DEFAULT_LOCALE is "am" and am.json is statically imported, so the
    // prerendered HTML is Amharic. "en" was never the right value here.
    expect(DEFAULT_LOCALE).toBe("am");
  });

  it("corrects the attribute for a reader whose stored locale differs", () => {
    localStorage.setItem("locale", "en");
    render(<Probe />);

    // Only knowable after hydration: the locale lives in localStorage and
    // middleware never sees it, so the static HTML cannot carry it.
    expect(document.documentElement.lang).toBe("en");
  });

  it("follows a language change made through the switcher", () => {
    localStorage.setItem("locale", "en");
    render(<Probe />);
    expect(document.documentElement.lang).toBe("en");

    setLocale("am");

    expect(document.documentElement.lang).toBe("am");
  });

  it("lets the URL's locale win over the stored preference", () => {
    localStorage.setItem("locale", "en");

    // /am/pricing is prerendered in Amharic with lang="am". If the stored
    // preference still won after hydration, the attribute would flip to "en"
    // on a page whose visible text is Amharic — which is the original defect,
    // reintroduced by the fix for it.
    render(
      <RouteLocaleProvider locale="am">
        <Probe />
      </RouteLocaleProvider>,
    );

    expect(document.documentElement.lang).toBe("am");
  });

  it("ignores a locale that has no dictionary", () => {
    render(<Probe />);

    // setLocale refuses a `coming_soon` locale, so the attribute must not move
    // to one either — an <html lang="om"> on a page rendered in Amharic would
    // be the same defect this file exists to prevent, pointed the other way.
    setLocale("om");

    expect(document.documentElement.lang).toBe(DEFAULT_LOCALE);
  });
});
