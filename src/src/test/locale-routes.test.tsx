import { describe, it, expect, afterAll, beforeEach, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import { renderToStaticMarkup } from "react-dom/server";

import {
  AVAILABLE_LOCALES,
  localeHref,
  negotiateLocale,
  parseAcceptLanguage,
  supportedLocales,
} from "@/lib/i18n/config";
import { RouteLocaleProvider } from "@/lib/i18n/route-locale";
import { useT } from "@/lib/i18n/useT";
import { alternatesFor, PUBLIC_ROUTES } from "@/lib/site-url";
import { publicSubset } from "@/lib/i18n/public-keys";
import { registerLocale } from "@/lib/i18n/translations";
import enTranslations from "@/lib/i18n/locales/en.json";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { TooltipProvider } from "@/components/ui/tooltip";
import { FaqContent } from "@/app/(marketing)/[locale]/faq/faq-content";
import { FeaturesContent } from "@/app/(marketing)/[locale]/features/features-content";
import { LandingContent } from "@/app/(marketing)/[locale]/landing-content";
import { PricingContent } from "@/app/(marketing)/[locale]/pricing/pricing-content";
import sitemap from "@/app/sitemap";
import { marketingMetadata } from "@/app/(marketing)/[locale]/page-metadata";

const replace = vi.fn();
vi.mock("next/navigation", () => ({
  useRouter: () => ({ replace, push: vi.fn(), prefetch: vi.fn() }),
  usePathname: () => "/",
  useSearchParams: () => new URLSearchParams(),
}));

// Imported after the mock is registered, because it calls useRouter on mount.
import { LocaleRedirect } from "@/app/(root)/locale-redirect";

/**
 * The public site is prerendered once per language at `/am/*` and `/en/*`.
 *
 * Before that, `DEFAULT_LOCALE` was `am` and the locale lived only in
 * localStorage, so the server emitted Amharic under an English `<title>` for
 * every visitor and every crawler, and there was exactly one URL for both
 * languages. Nothing in the suite could see it: no test rendered a page in a
 * non-default locale, and metadata is not produced at all in a unit render.
 */
describe("locale route coverage", () => {
  it("builds routes only for locales that have a dictionary", () => {
    expect([...AVAILABLE_LOCALES]).toEqual(["en", "am"]);

    // The four stub locales are listed in the switcher so the roadmap is
    // visible, but generating /om/pricing would publish a page of raw
    // translation keys — their JSON files are empty.
    const comingSoon = supportedLocales
      .filter((l) => l.status === "coming_soon")
      .map((l) => l.code);
    expect(comingSoon).not.toHaveLength(0);
    for (const code of comingSoon) {
      expect(AVAILABLE_LOCALES).not.toContain(code);
    }
  });
});

describe("locale negotiation", () => {
  it("prefers an explicit choice over what the browser asks for", () => {
    expect(negotiateLocale(["en", "am-ET"])).toBe("en");
  });

  it("matches a region subtag on its base language", () => {
    expect(negotiateLocale(["am-ET"])).toBe("am");
    expect(negotiateLocale([null, undefined, "en-GB"])).toBe("en");
  });

  it("refuses a locale whose dictionary is empty", () => {
    // Honouring `om` here would route a visitor to a page rendered as raw key
    // names — the same defect the language switcher already guards against.
    expect(negotiateLocale(["om"])).toBe("am");
  });

  it("falls back to the default when nothing matches", () => {
    expect(negotiateLocale(["fr", "de"])).toBe("am");
    expect(negotiateLocale([])).toBe("am");
  });

  it("orders Accept-Language by q weight, not by position", () => {
    // `en;q=0.5,am;q=0.9` says Amharic first despite English being written
    // first; taking the textual order would invert the visitor's preference.
    expect(parseAcceptLanguage("en;q=0.5,am;q=0.9")).toEqual(["am", "en"]);
    expect(parseAcceptLanguage("am,en;q=0.9")).toEqual(["am", "en"]);
    expect(parseAcceptLanguage(null)).toEqual([]);
  });
});

describe("locale-prefixed URLs", () => {
  it("prefixes a path without doubling the slash on the root", () => {
    expect(localeHref("am", "/")).toBe("/am");
    expect(localeHref("en", "/pricing")).toBe("/en/pricing");
  });

  it("declares every language of a page, plus an x-default", () => {
    // Two URLs carrying the same content are treated as duplicates unless they
    // name each other; x-default is the unprefixed URL that negotiates.
    expect(alternatesFor("/pricing", "en")).toEqual({
      canonical: "/en/pricing",
      languages: {
        en: "/en/pricing",
        am: "/am/pricing",
        "x-default": "/pricing",
      },
    });
  });

  it("keeps an unprefixed redirector canonical to itself", () => {
    // Pointing `/pricing`'s canonical at one of the two languages would tell a
    // crawler the x-default *is* that language.
    expect(alternatesFor("/pricing", null).canonical).toBe("/pricing");
  });
});

describe("sitemap", () => {
  const entries = sitemap();

  it("lists every public page in every available language", () => {
    expect(entries).toHaveLength(
      PUBLIC_ROUTES.length * AVAILABLE_LOCALES.length,
    );

    const paths = entries.map((e) => new URL(e.url).pathname || "/");
    expect(paths).toContain("/am/pricing");
    expect(paths).toContain("/en/pricing");
    expect(paths).toContain("/am");
    expect(paths).toContain("/en");
  });

  it("gives every entry the full hreflang set", () => {
    for (const entry of entries) {
      const languages = entry.alternates?.languages ?? {};
      expect(Object.keys(languages).sort()).toEqual(["am", "en", "x-default"]);
    }
  });

  it("does not list the unprefixed URLs as pages of their own", () => {
    // They hold a redirect and no content; each is already named as an
    // x-default by the two entries it points at.
    const paths = entries.map((e) => new URL(e.url).pathname || "/");
    expect(paths).not.toContain("/pricing");
  });
});

describe("page metadata", () => {
  it("renders the title and description in the page's own language", () => {
    const am = marketingMetadata("am", "/pricing", "pricing", {
      title: "Pricing",
      description: "Simple, transparent pricing for ETHR.",
    });
    const en = marketingMetadata("en", "/pricing", "pricing", {
      title: "Pricing",
      description: "Simple, transparent pricing for ETHR.",
    });

    expect(am.title).toBe("ዋጋ");
    expect(en.title).toBe("Pricing");
    expect(am.description).not.toBe(en.description);
    expect(String(am.description)).toMatch(/[ሀ-፿]/);
  });

  it("opts the landing page out of the '%s | ETHR' template", () => {
    // Its title already starts with the product name; the template would make
    // it "ETHR — … | ETHR".
    const home = marketingMetadata("en", "/", "home", {
      title: "ETHR — Ethiopian Workforce Operating System",
      description: "…",
    });
    expect(home.title).toEqual({
      absolute: "ETHR — Ethiopian Workforce Operating System",
    });
  });

  it("names its own og:image rather than relying on inheritance", () => {
    // Declaring `openGraph` at all replaces what a parent segment contributed,
    // file-convention images included — measured against a build, where
    // /en carried a card and /en/pricing carried none.
    const meta = marketingMetadata("en", "/faq", "faq", {
      title: "FAQ",
      description: "…",
    });
    expect(meta.openGraph?.images).toEqual([
      expect.objectContaining({ url: "/og.png" }),
    ]);
    expect(meta.openGraph?.locale).toBe("en_US");
  });
});

function LocaleProbe() {
  const { t, locale } = useT();
  return (
    <>
      <span data-testid="locale">{locale}</span>
      <span data-testid="text">{t("marketing.nav.pricing", "Pricing")}</span>
    </>
  );
}

describe("the URL decides the language", () => {
  beforeEach(() => {
    localStorage.setItem("locale", "en");
  });

  it("overrides the stored preference inside the locale-prefixed tree", () => {
    // Otherwise /am/pricing would render in English for anyone whose last visit
    // set `en` — the URL and the page disagreeing about what language it is.
    render(
      <RouteLocaleProvider locale="am">
        <LocaleProbe />
      </RouteLocaleProvider>,
    );

    expect(screen.getByTestId("locale")).toHaveTextContent("am");
    expect(screen.getByTestId("text")).toHaveTextContent("ዋጋ");
  });

  it("still reads the stored preference outside it", () => {
    render(<LocaleProbe />);
    expect(screen.getByTestId("locale")).toHaveTextContent("en");
    expect(screen.getByTestId("text")).toHaveTextContent("Pricing");
  });
});

describe("the unprefixed entry point", () => {
  beforeEach(() => {
    replace.mockClear();
    localStorage.clear();
    document.cookie = "locale=; path=/; max-age=0";
  });

  it("sends a visitor to the language they chose", async () => {
    localStorage.setItem("locale", "en");
    render(<LocaleRedirect path="/pricing" />);
    await waitFor(() => expect(replace).toHaveBeenCalledWith("/en/pricing"));
  });

  it("falls back to the cookie when localStorage is empty", async () => {
    document.cookie = "locale=en; path=/";
    render(<LocaleRedirect path="/" />);
    await waitFor(() => expect(replace).toHaveBeenCalledWith("/en"));
  });

  it("renders no translated text before it redirects", () => {
    // Rendering the landing page and redirecting afterwards is the flash of the
    // wrong language this route exists to remove.
    const { container } = render(<LocaleRedirect path="/" />);
    expect(container.textContent).toBe("");
  });

  it("offers both languages without JavaScript", () => {
    // Server-rendered, because React drops <noscript> children on the client —
    // and the prerendered HTML is the only place this markup ever matters. The
    // same block is visible in the build output: `.next/server/app/index.html`
    // carries "English አማርኛ" as its entire body text.
    const html = renderToStaticMarkup(<LocaleRedirect path="/pricing" />);

    expect(html).toContain('href="/am/pricing"');
    expect(html).toContain('href="/en/pricing"');
    expect(html).toContain('lang="am"');
    expect(html).toContain("አማርኛ");
  });
});

/**
 * The dictionary the browser is actually handed on `/en/*` has to be enough.
 *
 * Seventeen call sites on the public pages use a template-literal key and so
 * pass no fallback. The server renders them from `en.json` — the lazy import
 * resolves during the build — but the browser has nothing at the moment it
 * hydrates, so the layout ships `publicSubset(en)` and registers it before the
 * first client render. If that projection were missing a family, React would
 * throw away the server's HTML and paint `marketing.faq_page.what_is_q` until
 * the full chunk arrived.
 *
 * `scripts/i18n-check.js` enforces the same property statically, by prefix.
 * This asserts it the other way round: render each page with *only* the subset
 * loaded and look for a raw key in the output.
 */
describe("the dictionary shipped to a prerendered English page", () => {
  const full = enTranslations as unknown as Record<string, string>;

  // The test setup registers the whole of en.json. Narrow it to what a real
  // browser gets, then hand it back so no other file is affected.
  registerLocale("en", publicSubset(full));
  afterAll(() => registerLocale("en", full));

  function renderPublic(ui: React.ReactElement) {
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    });
    return render(
      <QueryClientProvider client={queryClient}>
        <TooltipProvider>
          <RouteLocaleProvider locale="en">{ui}</RouteLocaleProvider>
        </TooltipProvider>
      </QueryClientProvider>,
    );
  }

  // A dotted lowercase token is what an unresolved key looks like once it is
  // rendered as text. Domains and file names are the only legitimate ones.
  //
  // Every segment must start with a letter or underscore. `textContent` runs
  // adjacent nodes together with no separator, so a sentence ending in
  // "…integrations." followed by a price read as `integrations.2` — a false
  // positive, and the reason this is not simply [a-z0-9_]+.
  const RAW_KEY = /\b[a-z_][a-z0-9_]*(?:\.[a-z_][a-z0-9_]*)+\b/g;
  const ALLOWED = /\.(com|et|org|io|json|php|xml|csv|pdf)$/;

  function rawKeysIn(container: HTMLElement): string[] {
    const text = container.textContent ?? "";
    return [...new Set(text.match(RAW_KEY) ?? [])].filter(
      (token) => !ALLOWED.test(token),
    );
  }

  it.each([
    ["landing", <LandingContent key="l" />],
    ["features", <FeaturesContent key="f" />],
    ["faq", <FaqContent key="q" />],
    ["pricing", <PricingContent key="p" />],
  ])("covers every string on the %s page", (_name, ui) => {
    const { container } = renderPublic(ui);
    expect(rawKeysIn(container)).toEqual([]);
  });

  it("is a fraction of the full dictionary", () => {
    // 27 KB against 186 KB. If this ratio ever approaches 1 the projection has
    // stopped being a projection and the public pages are carrying the whole
    // dashboard's vocabulary.
    const subset = publicSubset(full);
    expect(Object.keys(subset).length).toBeLessThan(
      Object.keys(full).length / 4,
    );
    expect(Object.keys(subset).length).toBeGreaterThan(200);
  });
});
