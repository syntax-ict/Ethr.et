import type { Metadata } from "next";
import { LandingContent } from "./landing-content";
import { marketingMetadata } from "./page-metadata";

export async function generateMetadata({
  params,
}: {
  params: Promise<{ locale: string }>;
}): Promise<Metadata> {
  const { locale } = await params;

  return marketingMetadata(locale, "/", "home", {
    title: "ETHR — Ethiopian Workforce Operating System",
    description:
      "Enterprise-grade HR platform for Ethiopian organizations. Manage attendance, payroll, leave, and more — offline-first, bilingual, and built for Ethiopian labor law.",
  });
}

export default function LandingPage() {
  return <LandingContent />;
}
