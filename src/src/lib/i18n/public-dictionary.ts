import am from "./locales/am.json";
import en from "./locales/en.json";
import om from "./locales/om.json";
import sid from "./locales/sid.json";
import so from "./locales/so.json";
import ti from "./locales/ti.json";

import { publicSubset } from "./public-keys";

/**
 * The strings a public page needs, for one locale, ready to send to the browser.
 *
 * Imported only by the `[locale]` server layout, so these six files are read on
 * the server and never enter a client bundle — what reaches the browser is the
 * ~7.5 KB (gzipped) projection `publicSubset` returns, serialized once into the
 * RSC payload for the locale actually being served.
 *
 * Every locale gets one, Amharic included. Until 2026-10-03 this returned `null`
 * for `DEFAULT_LOCALE`, because `translations.ts` imported the whole of
 * `am.json` eagerly — which is exactly what put 56.6 KB of Amharic on every
 * public page, English ones included. The app shells register the full file
 * now (`amharic-dictionary.tsx`), and a public page carries only this.
 *
 * That only works because the projection is *complete* for a public page:
 * `scripts/i18n-check.js` fails on any public-page key outside
 * `PUBLIC_KEY_PREFIXES`, fallback or not. A key outside it would render the
 * Amharic sentence on the server and the English fallback in the browser's
 * first render — a hydration mismatch the reader sees as the page switching
 * language under them.
 */
const DICTIONARIES: Record<string, Record<string, string>> = {
  am,
  en,
  om,
  sid,
  so,
  ti,
};

/**
 * A dictionary lookup that does not depend on timing.
 *
 * `translateStatic` goes through the lazy loader, so on the server the answer
 * for `en` depends on whether the dynamic import has resolved yet — which it has
 * by the time a page body renders, and may not have when `generateMetadata` runs
 * a moment earlier. That is fine while the fallback and `en.json` agree (a gate
 * holds them equal), but it is not fine for text with no fallback at all, such
 * as the FAQ questions copied into JSON-LD: there the two outcomes are the real
 * sentence and the raw key.
 *
 * These six files are imported statically, so this is synchronous and gives the
 * same answer on every call.
 */
export function serverTranslate(
  locale: string,
  key: string,
  fallback?: string,
): string {
  return DICTIONARIES[locale]?.[key] ?? fallback ?? key;
}

export function publicDictionary(
  locale: string,
): Record<string, string> | null {
  const source = DICTIONARIES[locale];
  return source ? publicSubset(source) : null;
}
