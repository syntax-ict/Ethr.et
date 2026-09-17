import type { Metadata } from "next";
import { Inter } from "next/font/google";
import "@/styles/globals.css";
import { Providers } from "./providers";
import { SITE_URL } from "@/lib/site-url";
import { DEFAULT_LOCALE } from "@/lib/i18n/translations";

const inter = Inter({
  subsets: ["latin"],
  variable: "--font-inter",
});

export const metadata: Metadata = {
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

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    /* DEFAULT_LOCALE, not "en".

       am.json is statically imported and getLocale() returns DEFAULT_LOCALE on
       the server, so the prerendered HTML is Amharic — and this element
       declared English around it. A screen reader therefore pronounced Amharic
       with English rules, and a crawler indexed the page as English.

       This is the honest value for the static output. A reader whose stored
       preference differs has it corrected after hydration by syncDocumentLang;
       it cannot be known before, because the locale lives in localStorage and
       middleware never sees it. */
    <html lang={DEFAULT_LOCALE} suppressHydrationWarning>
      <body className={`${inter.variable} font-sans`}>
        <Providers>{children}</Providers>
      </body>
    </html>
  );
}
