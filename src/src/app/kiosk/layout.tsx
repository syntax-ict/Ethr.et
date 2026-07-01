import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Kiosk",
};

// Kiosk uses a bare layout — no app sidebar/header so the screen is
// fully dedicated to attendance check-in at a shared device.
export default function KioskLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return <div className="min-h-screen bg-background">{children}</div>;
}
