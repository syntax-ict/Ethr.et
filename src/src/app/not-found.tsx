// This is the one page outside every route group, so no RootShell wraps it
// and nothing else loads the stylesheet: the exported 404.html rendered in
// browser default styling (observed live 2026-10-20). Fonts come from the
// stylesheet's fallbacks here; next/font variables live on the shells.
import "@/styles/globals.css";
import { DEFAULT_LOCALE } from "@/lib/i18n/config";
import { DictionaryRegistrar } from "@/lib/i18n/dictionary-registrar";
import { publicDictionary } from "@/lib/i18n/public-dictionary";
import { NotFoundContent } from "./not-found-content";

/*
 * This page is outside the app shells, so nothing else registers Amharic for
 * it, while the exported HTML is in Amharic: without this the browser had no
 * Amharic to hydrate with, React error 418, and a title that flipped language
 * (found on the :8081 rehearsal, #385).
 *
 * Only its own strings, and from the server. This page's client code ships
 * with every route in the app, so when it registered the whole of `am.json`
 * itself (`AmharicDictionary`, #385) the full Amharic dictionary reached every
 * page, the English public site included: 514 KB, 89 KB of brotli, the largest
 * script a visitor downloaded (2026-10-31). A server component's imports never
 * reach the browser; these four strings do, in the RSC payload.
 */
const STRINGS = Object.fromEntries(
  Object.entries(publicDictionary(DEFAULT_LOCALE) ?? {}).filter(([key]) =>
    key.startsWith("not_found."),
  ),
);

export default function NotFound() {
  return (
    <DictionaryRegistrar locale={DEFAULT_LOCALE} dictionary={STRINGS}>
      <NotFoundContent />
    </DictionaryRegistrar>
  );
}
