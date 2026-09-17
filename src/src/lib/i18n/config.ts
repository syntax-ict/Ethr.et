/**
 * Locale facts, with no dictionary attached.
 *
 * Separate from `translations.ts` for one concrete reason: that module statically
 * imports `am.json` (242 KB) so the server can render Amharic synchronously, and
 * `middleware.ts` needs the locale *list* to negotiate `/`. Importing the list
 * from there would pull the whole Amharic dictionary into the Edge bundle, which
 * is evaluated on every single request including static assets.
 *
 * `translations.ts` re-exports everything here, so existing imports keep working.
 */

export const DEFAULT_LOCALE = "am";

/** The cookie `setLocale()` writes so the server can see a stored preference. */
export const LOCALE_COOKIE = "locale";

/**
 * `status` gates what the language switcher offers.
 *
 * `en` and `am` have full dictionaries (3,000+ keys each). The other four are
 * stub files awaiting professional translation — selecting one previously gave
 * the user a UI that silently fell back to Amharic or to raw key names, which
 * reads as a broken product rather than an unfinished translation. They stay
 * listed so the roadmap is visible, but render disabled with an explanation.
 *
 * Promoting a locale is a one-word edit here once its JSON is filled in.
 */
export type LocaleStatus = "available" | "coming_soon";

export const supportedLocales = [
  { code: "en", name: "English", nativeName: "English", status: "available" },
  { code: "am", name: "Amharic", nativeName: "አማርኛ", status: "available" },
  {
    code: "om",
    name: "Oromo",
    nativeName: "Afaan Oromoo",
    status: "coming_soon",
  },
  {
    code: "ti",
    name: "Tigrinya",
    nativeName: "ትግርኛ",
    status: "coming_soon",
  },
  {
    code: "so",
    name: "Somali",
    nativeName: "Soomaali",
    status: "coming_soon",
  },
  {
    code: "sid",
    name: "Sidama",
    nativeName: "Sidaamu Afoo",
    status: "coming_soon",
  },
] as const satisfies ReadonlyArray<{
  code: string;
  name: string;
  nativeName: string;
  status: LocaleStatus;
}>;

/**
 * The locale codes that have a real dictionary, in display order.
 *
 * This is the list `generateStaticParams` builds routes from, the list the
 * sitemap enumerates, and the list middleware negotiates against. Deriving it
 * here rather than writing `["en", "am"]` in three places is what makes
 * promoting a `coming_soon` locale the one-word edit promised above: flipping
 * `status` adds `/om/*` to the build, to the sitemap and to negotiation at
 * once, instead of adding a locale nobody can reach.
 */
export const AVAILABLE_LOCALES: readonly string[] = supportedLocales
  .filter((l) => l.status === "available")
  .map((l) => l.code);

export function isAvailableLocale(value: string | null | undefined): boolean {
  return typeof value === "string" && AVAILABLE_LOCALES.includes(value);
}

/**
 * Picks the best available locale from an ordered list of preferences.
 *
 * Used twice with different inputs and the same rules: `middleware.ts` passes
 * the cookie followed by the `Accept-Language` header, and the client-side
 * redirector passes localStorage followed by `navigator.languages`. Keeping one
 * implementation is what stops the two disagreeing — which a visitor would
 * experience as `/` sending them somewhere different depending on whether
 * middleware happened to run (it does not under `output: "export"`).
 *
 * Region subtags are matched on their base language, so `am-ET` and `en-GB`
 * both resolve. Anything unrecognised falls through to `DEFAULT_LOCALE`; a
 * `coming_soon` locale is not a match, because its dictionary is empty and
 * routing to it would render the page as raw translation keys.
 */
export function negotiateLocale(
  preferences: readonly (string | null | undefined)[],
): string {
  for (const preference of preferences) {
    if (!preference) continue;
    const base = preference.split("-")[0]?.toLowerCase();
    if (isAvailableLocale(preference)) return preference;
    if (isAvailableLocale(base)) return base as string;
  }
  return DEFAULT_LOCALE;
}

/**
 * Turns an `Accept-Language` header into an ordered preference list.
 *
 * Honours `q` weights, because a browser configured with English as a fallback
 * sends `am,en;q=0.9` — taking the header's textual order without sorting would
 * be right there but wrong for `en;q=0.5,am;q=0.9`, which says the opposite.
 */
export function parseAcceptLanguage(header: string | null): string[] {
  if (!header) return [];
  return header
    .split(",")
    .map((part) => {
      const [tag, ...params] = part.trim().split(";");
      const q = params
        .map((p) => p.trim())
        .find((p) => p.startsWith("q="))
        ?.slice(2);
      return { tag: tag?.trim() ?? "", q: q === undefined ? 1 : Number(q) };
    })
    .filter((entry) => entry.tag !== "" && !Number.isNaN(entry.q))
    .sort((a, b) => b.q - a.q)
    .map((entry) => entry.tag);
}

/**
 * Prefixes an in-site path with a locale.
 *
 * Every link in the marketing header, footer and page bodies was absolute and
 * unprefixed (`/pricing`). Left alone they would drop a reader who is on
 * `/en/features` onto the legacy redirector and, from there, wherever their
 * cookie pointed — so following a link could silently change language.
 *
 * Lives here rather than beside the hook that uses it because `sitemap.ts` is a
 * Server Component and cannot import from a `"use client"` module.
 */
export function localeHref(locale: string, path: string): string {
  if (!path.startsWith("/")) return path;
  return path === "/" ? `/${locale}` : `/${locale}${path}`;
}

/**
 * BCP-47-with-region forms, which is what Open Graph's `og:locale` expects —
 * `en`/`am` alone are rejected by most validators. Region choices are the ones
 * this product actually targets: Ethiopia for Amharic, and `en_US` for English
 * because it is the form Facebook's list recognises.
 */
export const OPEN_GRAPH_LOCALE: Record<string, string> = {
  en: "en_US",
  am: "am_ET",
};
