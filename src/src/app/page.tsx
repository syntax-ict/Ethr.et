import type { Metadata } from "next";
import { LandingContent } from "./landing-content";

export const metadata: Metadata = {
  title: "ETHR — Ethiopian Workforce Operating System",
  description:
    "Enterprise-grade HR platform for Ethiopian organizations. Manage attendance, payroll, leave, and more — offline-first, bilingual, and built for Ethiopian labor law.",
  openGraph: {
    title: "ETHR — Ethiopian Workforce Operating System",
    description: "Enterprise-grade HR platform for Ethiopian organizations.",
    type: "website",
  },
};

export default function LandingPage() {
  return <LandingContent />;
}
