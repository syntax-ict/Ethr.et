import type { Metadata } from "next";
import { Inter } from "next/font/google";
import "@/styles/globals.css";
import { Providers } from "./providers";
import { SITE_URL } from "@/lib/site-url";

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
    <html lang="en" suppressHydrationWarning>
      <body className={`${inter.variable} font-sans`}>
        <Providers>{children}</Providers>
      </body>
    </html>
  );
}
