import type { Metadata } from "next";
import { LocaleRedirect } from "./locale-redirect";
import { alternatesFor } from "@/lib/site-url";

/**
 * `/` is the `x-default` of the site: it holds no content of its own and sends
 * each visitor to `/am` or `/en`. The landing page itself moved to
 * `(marketing)/[locale]/page.tsx`, where it is prerendered once per language
 * with a matching `<html lang>`, `<title>` and description — which the single
 * unprefixed page could not have, because a root layout sits above every
 * dynamic segment and therefore cannot know which language was asked for.
 *
 * `noindex, follow`, like every other redirector (2026-10-19). Indexed, `/`
 * was a page with no headline and two links competing with `/am` and `/en`
 * for the site's own name; a crawler now follows through to the pages that
 * hold the content.
 */
export const metadata: Metadata = {
  robots: { index: false, follow: true },
  alternates: alternatesFor("/", null),
};

export default function LocaleEntryPage() {
  return <LocaleRedirect path="/" />;
}
