import type { Metadata, Viewport } from "next";
import { baseMetadata, RootShell, baseViewport } from "../root-shell";
import { DEFAULT_LOCALE } from "@/lib/i18n/translations";
import { AmharicDictionary } from "@/lib/i18n/amharic-dictionary";

// No pinch zoom on a shared terminal: a zoomed-in punch screen stays that
// way for the next person, who cannot see the keypad.
export const viewport: Viewport = {
  ...baseViewport,
  maximumScale: 1,
  userScalable: false,
};

export const metadata: Metadata = {
  ...baseMetadata,
  // Spelled out rather than left to the `%s | ETHR` template: this file is now
  // a root layout, and a root's own title is not run through its own template.
  title: { default: "Kiosk | ETHR", template: "%s | ETHR" },
};

// Kiosk uses a bare layout — no app sidebar/header so the screen is
// fully dedicated to attendance check-in at a shared device.
export default function KioskLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <RootShell lang={DEFAULT_LOCALE}>
      <AmharicDictionary>
        <div className="min-h-screen bg-background">{children}</div>
      </AmharicDictionary>
    </RootShell>
  );
}
