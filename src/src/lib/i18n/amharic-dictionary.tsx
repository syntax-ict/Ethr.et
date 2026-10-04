"use client";

import am from "./locales/am.json";
import { registerLocale } from "./translations";

/*
 * The full Amharic dictionary, for the authenticated app.
 *
 * Amharic is the default locale, so the app's first render needs every string
 * synchronously. Registering at module evaluation does that: a client module
 * evaluates before React renders anything it exports, and so before anything
 * below `AmharicDictionary` renders — on the server render and in the browser
 * alike.
 *
 * Only the app shells — (auth), (dashboard), kiosk and offline — render this,
 * so only their bundles carry the file. It used to be imported by
 * `translations.ts`, which every page loads, and so shipped 56.6 KB gzipped to
 * the public site in both languages (BASELINE §18). The public pages register a
 * projection instead (`public-dictionary.ts`). `registerLocale` merges, so
 * moving between the two in one session never leaves either half short.
 */
registerLocale("am", am);

export function AmharicDictionary({ children }: { children: React.ReactNode }) {
  return <>{children}</>;
}
