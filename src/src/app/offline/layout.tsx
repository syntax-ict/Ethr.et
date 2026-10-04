import type { Metadata } from "next";
import { baseMetadata, RootShell } from "../root-shell";
import { DEFAULT_LOCALE } from "@/lib/i18n/translations";
import { AmharicDictionary } from "@/lib/i18n/amharic-dictionary";

export const metadata: Metadata = {
  ...baseMetadata,
  title: { default: "Offline | ETHR", template: "%s | ETHR" },
};

/**
 * `/offline` had no layout of its own and inherited `app/layout.tsx`. With that
 * file removed every top-level segment needs a root of its own, and this is the
 * smallest one that still gets the fallback page the stylesheet, the font and
 * the service-worker registration it has always had.
 */
export default function OfflineLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <RootShell lang={DEFAULT_LOCALE}>
      <AmharicDictionary>{children}</AmharicDictionary>
    </RootShell>
  );
}
