import am from "./locales/am.json";
import en from "./locales/en.json";
import om from "./locales/om.json";
import sid from "./locales/sid.json";
import so from "./locales/so.json";
import ti from "./locales/ti.json";

import { DEFAULT_LOCALE } from "./config";
import { publicSubset } from "./public-keys";

/**
 * The strings a public page needs, for one locale, ready to send to the browser.
 *
 * Imported only by the `[locale]` server layout, so these six files are read on
 * the server and never enter a client bundle — what reaches the browser is the
 * ~7.5 KB (gzipped) projection `publicSubset` returns, serialized once into the
 * RSC payload for the locale actually being served.
 *
 * `null` for `DEFAULT_LOCALE`: `am.json` is already imported eagerly by
 * `translations.ts`, so sending it again would be pure duplication.
 */
const DICTIONARIES: Record<string, Record<string, string>> = {
  am,
  en,
  om,
  sid,
  so,
  ti,
};

export function publicDictionary(
  locale: string,
): Record<string, string> | null {
  if (locale === DEFAULT_LOCALE) return null;

  const source = DICTIONARIES[locale];
  return source ? publicSubset(source) : null;
}
