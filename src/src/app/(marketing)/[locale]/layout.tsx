import type { Metadata } from "next";
import { MarketingHeader } from "@/components/layouts/marketing-header";
import { MarketingFooter } from "@/components/layouts/marketing-footer";
import { AVAILABLE_LOCALES } from "@/lib/i18n/config";
import { DictionaryRegistrar } from "@/lib/i18n/dictionary-registrar";
import {
  publicDictionary,
  serverTranslate,
} from "@/lib/i18n/public-dictionary";
import { baseMetadata, RootShell } from "../../root-shell";

/**
 * One build per available locale — `/am/*` and `/en/*` — and nothing else.
 *
 * Read from `AVAILABLE_LOCALES` rather than written out, so the four
 * `coming_soon` locales stay out of the build. Generating them would publish
 * `/om/pricing` as a page of raw translation keys, because their dictionaries
 * are empty files; flipping one to `available` adds its routes here, to the
 * sitemap and to negotiation at the same moment.
 */
export function generateStaticParams() {
  return AVAILABLE_LOCALES.map((locale) => ({ locale }));
}

/**
 * Anything outside that list is a 404 rather than a rendered page.
 *
 * Without this, `/xx/pricing` would render on demand with `lang="xx"` and an
 * entirely untranslated body — and, because `[locale]` sits at the top level,
 * so would every typo'd path a crawler invents. It also keeps the route honest
 * under `output: "export"`, where there is no server to render a fallback at
 * all.
 */
export const dynamicParams = false;

export async function generateMetadata({
  params,
}: {
  params: Promise<{ locale: string }>;
}): Promise<Metadata> {
  const { locale } = await params;

  return {
    ...baseMetadata,
    title: {
      default: serverTranslate(
        locale,
        "marketing.meta.site.title",
        "ETHR — Ethiopian Workforce Operating System",
      ),
      template: "%s | ETHR",
    },
    description: serverTranslate(
      locale,
      "marketing.meta.site.description",
      "Enterprise-grade, multi-tenant HR management system built for Ethiopian organizations.",
    ),
  };
}

/**
 * The root layout for the public site.
 *
 * It is a *root* layout — it renders `<html>` and `<body>` — because that is the
 * only place `lang` can be set, and `app/layout.tsx` (which used to be the only
 * one) sits above every dynamic segment and so could never read `locale`. That
 * is why the prerendered `/pricing` declared `lang="am"` for an English reader.
 * Removing the single root and giving each top-level tree its own is Next's
 * supported answer; the URLs are unchanged, because route groups are erased
 * from the path.
 *
 * `routeLocale` makes the URL authoritative for *content* as well as for the
 * attribute: `useT` reads it from context in preference to the stored
 * preference, so `/en/pricing` renders English even for a reader whose
 * localStorage says `am`. Without that, the page's language and its URL could
 * disagree the moment it hydrated.
 *
 * `DictionaryRegistrar` closes the other half of that: only `am.json` is loaded
 * eagerly, so a browser hydrating `/en/*` has no English strings yet and would
 * repaint the page with raw translation keys before the lazy chunk arrived. It
 * receives the ~7.5 KB (gzipped) public projection of the locale's dictionary
 * and registers it before anything below renders — see `public-keys.ts` for why
 * a projection rather than the whole 45 KB file, and for the gate that keeps it
 * complete.
 *
 * A server component, so it can await `params`. The header and footer are still
 * client components; they are simply rendered from here instead of from a
 * `"use client"` layout that existed only to hold them.
 */
export default async function MarketingLocaleLayout({
  children,
  params,
}: {
  children: React.ReactNode;
  params: Promise<{ locale: string }>;
}) {
  const { locale } = await params;

  return (
    <RootShell lang={locale} routeLocale={locale}>
      <DictionaryRegistrar
        locale={locale}
        dictionary={publicDictionary(locale)}
      >
        <div className="flex min-h-screen flex-col bg-background">
          <MarketingHeader />
          <main className="flex-1">{children}</main>
          <MarketingFooter />
        </div>
      </DictionaryRegistrar>
    </RootShell>
  );
}
