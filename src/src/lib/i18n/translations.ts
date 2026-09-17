import amTranslations from "./locales/am.json";

const loaded: Record<string, Record<string, string>> = {
  am: amTranslations,
};

/**
 * Every locale except the eager-loaded `am` is code-split behind a dynamic
 * import so its dictionary ships only when selected. `om`/`ti`/`so`/`sid` are
 * stub files today — a missing key falls back to the key name itself (see
 * `t()`), which lets `useT`'s caller-supplied English fallback string (already
 * passed at every call site) take over instead of silently substituting
 * Amharic text nobody asked for. The loading path is identical regardless, so
 * filling these in later is a data change with no code change. Adding a
 * locale = a file here + an entry in `supportedLocales`.
 */
const lazyLoaders: Record<
  string,
  () => Promise<{ default: Record<string, string> }>
> = {
  en: () => import("./locales/en.json"),
  om: () => import("./locales/om.json"),
  ti: () => import("./locales/ti.json"),
  so: () => import("./locales/so.json"),
  sid: () => import("./locales/sid.json"),
};

const loadPromises: Record<string, Promise<void>> = {};

function ensureLocaleLoaded(locale: string): void {
  if (loaded[locale] || locale === "am") return;
  const loader = lazyLoaders[locale];
  if (loader && !loadPromises[locale]) {
    loadPromises[locale] = loader().then((mod) => {
      loaded[locale] = mod.default;
    });
  }
}

export function t(key: string, locale: string = "en"): string {
  ensureLocaleLoaded(locale);
  // No cross-locale fallback here on purpose: silently substituting Amharic
  // for a user who explicitly chose a different language is the bug this
  // function used to have. Returning the raw key lets `useT`'s caller-supplied
  // English fallback string win instead — every call site already provides one.
  return loaded[locale]?.[key] ?? key;
}

export const DEFAULT_LOCALE = "am";

/**
 * Reads the active locale, rejecting anything without a real dictionary.
 *
 * localStorage is user-writable and outlives releases, so an unvalidated read
 * could hand back `om` — a stub locale — and render the whole app as raw key
 * names while the switcher confidently displayed "Afaan Oromoo". Only locales
 * marked `available` in `supportedLocales` are honoured; anything else falls
 * back to the default. (`supportedLocales` is declared below but only read at
 * call time, which is always after module init.)
 */
export function getLocale(): string {
  if (typeof window === "undefined") return DEFAULT_LOCALE;

  const stored = localStorage.getItem("locale");
  const isAvailable = supportedLocales.some(
    (l) => l.code === stored && l.status === "available",
  );

  return isAvailable && stored ? stored : DEFAULT_LOCALE;
}

export function setLocale(locale: string): void {
  if (typeof window === "undefined") return;

  // Mirrors the guard in `getLocale` — refuse to persist a locale we cannot
  // actually render, so the switcher and the rendered UI can never disagree.
  const isAvailable = supportedLocales.some(
    (l) => l.code === locale && l.status === "available",
  );
  if (!isAvailable) return;

  ensureLocaleLoaded(locale);
  localStorage.setItem("locale", locale);
  syncDocumentLang(locale);
  window.dispatchEvent(new CustomEvent("locale-changed", { detail: locale }));
}

/**
 * Keeps `<html lang>` equal to the locale actually being rendered.
 *
 * The root layout hardcoded `lang="en"` while `DEFAULT_LOCALE` is `am`, so the
 * server emitted Amharic content inside an element declaring English — the
 * load-bearing finding of the original audit, confirmed against a build:
 * `.next/server/app/index.html` carries `<html lang="en">` around
 * `<h1>ለኢትዮጵያ ድርጅቶች ሙሉ የሰው ሃብት መድረክ</h1>`.
 *
 * That is not cosmetic. `lang` is what a screen reader uses to choose
 * pronunciation rules, so Amharic was being read aloud as English; it is what
 * search engines use to decide what language a page is in; and it drives
 * hyphenation, font fallback and spellchecking.
 *
 * The layout now renders DEFAULT_LOCALE, which is correct for the static HTML
 * every visitor and crawler receives first. This function corrects it for a
 * reader whose stored preference differs, which cannot be known before
 * hydration — there is no cookie, by design: locale lives in localStorage
 * only, and middleware never sees it.
 */
export function syncDocumentLang(locale: string): void {
  if (typeof document === "undefined") return;
  if (document.documentElement.lang !== locale) {
    document.documentElement.lang = locale;
  }
}

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

export function registerLocale(
  code: string,
  data: Record<string, string>,
): void {
  loaded[code] = data;
}

export function preloadLocale(locale: string): Promise<void> {
  ensureLocaleLoaded(locale);
  return loadPromises[locale] ?? Promise.resolve();
}

if (typeof window !== "undefined") {
  // Via getLocale(), not raw localStorage — otherwise a stale stub locale
  // triggers a pointless dynamic import of an empty dictionary on boot.
  const active = getLocale();
  if (active !== DEFAULT_LOCALE) {
    ensureLocaleLoaded(active);
  }
}
