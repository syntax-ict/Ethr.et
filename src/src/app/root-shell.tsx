import type { Metadata } from "next";
import { Inter } from "next/font/google";
import "@/styles/globals.css";
import { Providers } from "./providers";
import { RouteLocaleProvider } from "@/lib/i18n/route-locale";
import { SITE_URL } from "@/lib/site-url";

const inter = Inter({
  subsets: ["latin"],
  variable: "--font-inter",
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
      <body className={`${inter.variable} font-sans`}>
        {routeLocale ? (
          <RouteLocaleProvider locale={routeLocale}>{tree}</RouteLocaleProvider>
        ) : (
          tree
        )}
      </body>
    </html>
  );
}
