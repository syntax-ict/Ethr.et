import type { Metadata } from "next";
import { LocaleRedirect } from "../locale-redirect";
import { alternatesFor } from "@/lib/site-url";

/**
 * The pre-locale URL, kept working.
 *
 * `/features` was the real page until the locale prefix landed; links to it exist in
 * the auth screens, in e2e specs and potentially in anyone's bookmarks. It now
 * forwards to `/am/features` or `/en/features`.
 *
 * `noindex, follow`: there is nothing here to index — the indexable pages are
 * the two it points at, and they declare each other through `hreflang` — but a
 * crawler should still follow through to them rather than treat this as a dead
 * end. `languages` is emitted so the relationship is visible from this URL too.
 */
export const metadata: Metadata = {
  robots: { index: false, follow: true },
  alternates: alternatesFor("/features", null),
};

export default function FeaturesRedirectPage() {
  return <LocaleRedirect path="/features" />;
}
