import type { Metadata } from "next";
import { LandingContent } from "./landing-content";
import { marketingMetadata } from "./page-metadata";
import { JsonLd } from "@/components/marketing/json-ld";
import {
  organizationJsonLd,
  websiteJsonLd,
} from "@/components/marketing/structured-data";

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

export default async function LandingPage({
  params,
}: {
  params: Promise<{ locale: string }>;
}) {
  const { locale } = await params;

  return (
    <>
      <JsonLd data={websiteJsonLd(locale)} />
      <JsonLd data={organizationJsonLd(locale)} />
      <LandingContent />
    </>
  );
}
