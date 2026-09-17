import type { Metadata } from "next";
import {
  AVAILABLE_LOCALES,
  OPEN_GRAPH_LOCALE,
  localeHref,
} from "@/lib/i18n/config";
import { translateStatic } from "@/lib/i18n/translations";
import {
  OG_IMAGE_ALT,
  OG_IMAGE_PATH,
  OG_IMAGE_SIZE,
} from "@/components/marketing/og-meta";
import { alternatesFor } from "@/lib/site-url";

/**
 * The `<head>` for one public page, in one language.
 *
 * Before the locale prefix existed, every one of these pages exported a single
 * hardcoded English `metadata` object, so the Amharic render — which is what
 * the server actually emitted, `DEFAULT_LOCALE` being `am` — was served under
 * an English `<title>` and an English description. A crawler read the page as
 * English; a person sharing it got an English preview card of an Amharic page.
 *
 * Both strings come from the dictionaries, with the English text here as the
 * fallback. `am.json` is the only one imported eagerly, so whether `en` resolves
 * from `en.json` or from that fallback depends on whether the lazy import has
 * landed by the time metadata is generated — which is exactly why
 * `scripts/i18n-check.js` holds the two equal for every string on a public page.
 * The answer is then the same either way, and nothing depends on the timing.
 *
 * `alternatesFor` emits the `hreflang` pair plus `x-default`; `openGraph`
 * repeats the title and description rather than carrying a second, shorter copy
 * of the same claim, which is how the old files ended up with four variants of
 * one sentence and no way to tell which was current.
 */
export function marketingMetadata(
  locale: string,
  path: string,
  page: string,
  fallback: { title: string; description: string },
): Metadata {
  const title = translateStatic(
    `marketing.meta.${page}.title`,
    locale,
    fallback.title,
  );
  const description = translateStatic(
    `marketing.meta.${page}.description`,
    locale,
    fallback.description,
  );

  return {
    /* The landing page's title is already "ETHR — …", and the layout's
       `%s | ETHR` template would make it "ETHR — … | ETHR". `absolute` opts one
       page out of its parent's template, which is exactly what a homepage
       title needs and what the old `app/page.tsx` was missing. */
    title: path === "/" ? { absolute: title } : title,
    description,
    alternates: alternatesFor(path, locale),
    openGraph: {
      title,
      description,
      type: "website",
      url: localeHref(locale, path),
      locale: OPEN_GRAPH_LOCALE[locale],
      alternateLocale: AVAILABLE_LOCALES.filter((code) => code !== locale).map(
        (code) => OPEN_GRAPH_LOCALE[code]!,
      ),
      /* Named rather than inherited. Declaring `openGraph` at all replaces what
         a parent segment contributed, file-convention images included — so a
         page that wants its own og:title has to state its own og:image too, or
         it ships a card with no picture. */
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
}
