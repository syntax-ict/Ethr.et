import type { Metadata } from "next";
import { baseMetadata, RootShell } from "../root-shell";
import { DEFAULT_LOCALE } from "@/lib/i18n/translations";

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
      <div className="min-h-screen bg-background">{children}</div>
    </RootShell>
  );
}
