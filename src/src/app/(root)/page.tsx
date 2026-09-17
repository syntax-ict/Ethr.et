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
 */
export const metadata: Metadata = {
  alternates: alternatesFor("/", null),
};

export default function LocaleEntryPage() {
  return <LocaleRedirect path="/" />;
}
