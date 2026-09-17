import amTranslations from "./locales/am.json";
import { DEFAULT_LOCALE, LOCALE_COOKIE, isAvailableLocale } from "./config";

/**
 * The locale list, the default and the cookie name now live in `./config`,
 * which imports nothing — `middleware.ts` needs them and must not pull the
 * 242 KB Amharic dictionary below into the Edge bundle. Re-exported here so
 * every existing `from "@/lib/i18n/translations"` import keeps working.
 */
export {
  DEFAULT_LOCALE,
  LOCALE_COOKIE,
  AVAILABLE_LOCALES,
  isAvailableLocale,
  negotiateLocale,
  parseAcceptLanguage,
  supportedLocales,
} from "./config";
export type { LocaleStatus } from "./config";

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

/**
 * `useT`'s translate function, without the hook.
 *
 * Server Components cannot call hooks, but `generateMetadata` has to produce a
 * `<title>` in the page's own language — that is the whole point of the
 * locale-prefixed routes. This is the same logic `useT` uses, exported so the
 * two cannot drift: look the key up, and fall back to the caller's English
 * string when the dictionary has no entry (on the server that is every locale
 * except `am`, the only one imported eagerly).
 *
 * That fallback is load-bearing rather than incidental. It is what lets `/en/*`
 * render real English in the *static* HTML without shipping `en.json` to the
 * server or into the client bundle — and because the first client render uses
 * exactly the same fallback, server and client agree, so there is no hydration
 * mismatch. `scripts/i18n-check.js` enforces that `en.json`'s value equals the
 * fallback for every string reachable from a public page, which is what stops
 * the text changing under the reader once the dictionary arrives.
 */
export function translateStatic(
  key: string,
  locale: string,
  fallback?: string,
  replacements?: Record<string, string | number>,
): string {
  const result = t(key, locale);
  const base = result !== key ? result : (fallback ?? key);

  if (!replacements) return base;

  return Object.entries(replacements).reduce(
    (out, [name, value]) => out.split(`:${name}`).join(String(value)),
    base,
  );
}

/**
 * Reads the active locale, rejecting anything without a real dictionary.
 *
 * localStorage is user-writable and outlives releases, so an unvalidated read
 * could hand back `om` — a stub locale — and render the whole app as raw key
 * names while the switcher confidently displayed "Afaan Oromoo". Only locales
 * marked `available` in `supportedLocales` are honoured; anything else falls
 * back to the default.
 *
 * This is the *stored* preference only. Inside `/am/*` and `/en/*` the URL is
 * authoritative and `useT` reads it from React context instead — see
 * `route-locale.tsx`.
 */
export function getLocale(): string {
  if (typeof window === "undefined") return DEFAULT_LOCALE;

  const stored = localStorage.getItem("locale");

  return isAvailableLocale(stored) && stored ? stored : DEFAULT_LOCALE;
}

export function setLocale(locale: string): void {
  if (typeof window === "undefined") return;

  // Mirrors the guard in `getLocale` — refuse to persist a locale we cannot
  // actually render, so the switcher and the rendered UI can never disagree.
  if (!isAvailableLocale(locale)) return;

  ensureLocaleLoaded(locale);
  localStorage.setItem("locale", locale);
  writeLocaleCookie(locale);
  syncDocumentLang(locale);
  window.dispatchEvent(new CustomEvent("locale-changed", { detail: locale }));
}

/**
 * Mirrors the stored locale into a cookie so the *server* can see it.
 *
 * localStorage stays the source of truth — it is what `getLocale()` reads and
 * what the app has always used. But it is unreachable from middleware, which is
 * why `/` could not send a returning reader to the language they had already
 * chosen: the request carried no signal at all, leaving only a guess from
 * `Accept-Language` or a client-side redirect after the wrong page had painted.
 *
 * Deliberately not `HttpOnly` (the client writes it) and deliberately not
 * `Secure` (development is plain http, and a locale is not a secret). It holds
 * a language code and nothing else.
 *
 * Read back by `middleware.ts`. If it is absent — a first visit, a cleared
 * cookie jar, or a static export where middleware never runs — negotiation
 * falls back to `Accept-Language` on the server and `navigator.languages` in
 * the browser. Nothing depends on it being present.
 */
function writeLocaleCookie(locale: string): void {
  if (typeof document === "undefined") return;
  document.cookie = `${LOCALE_COOKIE}=${locale}; path=/; max-age=31536000; samesite=lax`;
}

/**
 * Keeps `<html lang>` equal to the locale actually being rendered.
 *
 * The root layout hardcoded `lang="en"` while `DEFAULT_LOCALE` is `am`, so the
 * server emitted Amharic content inside an element declaring English — the
 * load-bearing finding of the original audit, confirmed against a build:
 * `.next/server/app/index.html` carried `<html lang="en">` around
 * `<h1>ለኢትዮጵያ ድርጅቶች ሙሉ የሰው ሃብት መድረክ</h1>`.
 *
 * That is not cosmetic. `lang` is what a screen reader uses to choose
 * pronunciation rules, so Amharic was being read aloud as English; it is what
 * search engines use to decide what language a page is in; and it drives
 * hyphenation, font fallback and spellchecking.
 *
 * On `/am/*` and `/en/*` the server now emits the locale the URL asked for and
 * this function is a no-op. Everywhere else — the dashboard, auth, kiosk — the
 * layout emits `DEFAULT_LOCALE`, which is correct for the static HTML, and this
 * corrects it for a reader whose stored preference differs, which cannot be
 * known before hydration.
 */
export function syncDocumentLang(locale: string): void {
  if (typeof document === "undefined") return;
  if (document.documentElement.lang !== locale) {
    document.documentElement.lang = locale;
  }
}

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
