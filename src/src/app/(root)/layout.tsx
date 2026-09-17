import type { Metadata } from "next";
import { baseMetadata, RootShell } from "../root-shell";
import { DEFAULT_LOCALE, translateStatic } from "@/lib/i18n/translations";
import {
  OG_IMAGE_ALT,
  OG_IMAGE_PATH,
  OG_IMAGE_SIZE,
} from "@/components/marketing/og-meta";

/**
 * Titled in `DEFAULT_LOCALE`, to match the `lang` this tree declares.
 *
 * These pages hold no prose, but they do hold a `<title>`, and an English title
 * inside `<html lang="am">` is the same mismatch the locale prefix exists to
 * remove — in one line instead of a whole page. `/` is the `x-default`, so the
 * site's default language is the right one for it to speak.
 */
export const metadata: Metadata = {
  ...baseMetadata,
  title: {
    default: translateStatic(
      "marketing.meta.site.title",
      DEFAULT_LOCALE,
      "ETHR — Ethiopian Workforce Operating System",
    ),
    template: "%s | ETHR",
  },
  description: translateStatic(
    "marketing.meta.site.description",
    DEFAULT_LOCALE,
    "Enterprise-grade, multi-tenant HR management system built for Ethiopian organizations.",
  ),
  openGraph: {
    type: "website",
    title: translateStatic(
      "marketing.meta.site.title",
      DEFAULT_LOCALE,
      "ETHR — Ethiopian Workforce Operating System",
    ),
    description: translateStatic(
      "marketing.meta.site.description",
      DEFAULT_LOCALE,
      "Enterprise-grade, multi-tenant HR management system built for Ethiopian organizations.",
    ),
    images: [
      {
        url: OG_IMAGE_PATH,
        width: OG_IMAGE_SIZE.width,
        height: OG_IMAGE_SIZE.height,
        alt: OG_IMAGE_ALT,
      },
    ],
  },
};

/**
 * Root layout for the unprefixed public URLs — `/`, and the six legacy paths
 * that now forward into a locale.
 *
 * `DEFAULT_LOCALE` is the honest value here because none of these pages render
 * translated text: they are redirectors whose only visible content is a
 * `<noscript>` pair of links labelled in their own languages. Nothing under
 * this layout can be read in the wrong language, because nothing under it can
 * be read at all.
 */
export default function RootRedirectLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return <RootShell lang={DEFAULT_LOCALE}>{children}</RootShell>;
}
