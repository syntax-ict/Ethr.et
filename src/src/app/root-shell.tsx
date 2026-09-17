import type { Metadata } from "next";
import { Inter, Noto_Sans_Ethiopic } from "next/font/google";
import "@/styles/globals.css";
import { Providers } from "./providers";
import { RouteLocaleProvider } from "@/lib/i18n/route-locale";
import { SITE_URL } from "@/lib/site-url";

const inter = Inter({
  subsets: ["latin"],
  variable: "--font-inter",
});

/**
 * The Ethiopic face, actually shipped rather than merely named.
 *
 * `globals.css` already listed "Noto Sans Ethiopic" in `--font-sans`, but as a
 * *local* family name — so it applied only to a visitor who happened to have it
 * installed. Windows and macOS do not, which is the majority of the audience
 * for a site whose default language is Amharic: the text fell through to a
 * generic sans, and on a machine with no Ethiopic face at all it rendered as
 * tofu boxes.
 *
 * Self-hosted by `next/font`, so no request reaches Google at runtime — which
 * also keeps the privacy page's "no data leaves the country" claim true.
 *
 * `preload: false` is the load-bearing option, and it is measured rather than
 * cautious. The Ethiopic subset is **198 KB** against Inter's 48 KB — it covers
 * a syllabary of several hundred glyphs — and `next/font` preloads by default,
 * which emits a `<link rel="preload">` on every page and so fetches it eagerly
 * on `/en/*` and on all 64 dashboard routes, where not one Ethiopic glyph is
 * painted. Without the preload the generated `@font-face` still carries the
 * subset's `unicode-range` (U+1200–137F and the Ethiopic extensions), and a
 * browser requests a face only when a glyph in that range is laid out: English
 * pages never fetch it at all, and Amharic pages fetch it as soon as the
 * stylesheet is parsed. `display: "swap"` covers the gap on those, which is the
 * right side of the trade — a brief fallback on the Amharic pages costs less
 * than 198 KB on every page that has no use for it, on the mobile networks this
 * product is actually used over.
 */
const notoEthiopic = Noto_Sans_Ethiopic({
  subsets: ["ethiopic"],
  variable: "--font-noto-ethiopic",
  display: "swap",
  preload: false,
});

/**
 * Everything the old single root layout put in `<head>`, minus the parts a
 * locale changes.
 *
 * There are now several root layouts (see `RootShell` below) and each exports
 * its own `metadata`. Spreading this object rather than restating it is what
 * keeps `metadataBase`, the icons and the manifest identical across all of
 * them — the failure mode being a tree that quietly loses its favicon or, worse,
 * emits relative `og:image` URLs that every chat preview resolves against its
 * own origin.
 */
export const baseMetadata: Metadata = {
  // Absolute URLs for Open Graph and canonical links. Without this Next emits
  // relative og:url and og:image values, which every social and chat preview
  // resolves against its own origin — so a shared link renders a broken card.
  metadataBase: new URL(SITE_URL),
  title: {
    template: "%s | ETHR",
    default: "ETHR — Ethiopian Workforce Operating System",
  },
  description:
    "Enterprise-grade, multi-tenant HR management system built for Ethiopian organizations.",
  icons: {
    icon: "/favicon.ico",
    apple: "/icons/icon-192.png",
  },
  manifest: "/manifest.json",
  appleWebApp: {
    capable: true,
    statusBarStyle: "default",
    title: "ETHR",
  },
};

/**
 * The `<html>`/`<body>` shell, shared by every root layout.
 *
 * `app/layout.tsx` is gone, and that is the point. A root layout is the only
 * place `<html lang>` can be set, and a file at `app/layout.tsx` sits above
 * every dynamic segment — so it can never know which locale the URL asked for.
 * That is why the prerendered `/pricing` declared `lang="am"` no matter who
 * requested it. Next's answer is multiple root layouts: remove the file, and
 * let the topmost layout of each top-level segment be a root of its own.
 *
 * So `(marketing)/[locale]/layout.tsx` is a root layout that reads `locale`
 * from the URL, while `(root)`, `(auth)`, `(dashboard)`, `kiosk` and `offline`
 * are roots that render `DEFAULT_LOCALE`. None of their URLs changed: route
 * groups are erased from the path.
 *
 * `routeLocale` is passed only by the locale-prefixed tree. Where it is set the
 * language is a fact about the request and `useT` uses it directly; where it is
 * absent `useT` keeps reading the stored preference, which is the only signal
 * the dashboard has.
 */
export function RootShell({
  lang,
  routeLocale,
  children,
}: Readonly<{
  lang: string;
  routeLocale?: string;
  children: React.ReactNode;
}>) {
  const tree = <Providers>{children}</Providers>;

  return (
    <html lang={lang} suppressHydrationWarning>
      <body className={`${inter.variable} ${notoEthiopic.variable} font-sans`}>
        {routeLocale ? (
          <RouteLocaleProvider locale={routeLocale}>{tree}</RouteLocaleProvider>
        ) : (
          tree
        )}
      </body>
    </html>
  );
}
