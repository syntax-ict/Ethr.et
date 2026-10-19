import type { Metadata } from "next";
import { LandingContent } from "./landing-content";
import { marketingMetadata } from "./page-metadata";
import { JsonLd } from "@/components/marketing/json-ld";
import {
  organizationJsonLd,
  softwareApplicationJsonLd,
  websiteJsonLd,
} from "@/components/marketing/structured-data";

export async function generateMetadata({
  params,
}: {
  params: Promise<{ locale: string }>;
}): Promise<Metadata> {
  const { locale } = await params;

  return marketingMetadata(locale, "/", "home", {
    title: "HR, Payroll and Attendance Software for Ethiopia | ETHR",
    description:
      "HR, payroll, attendance and leave software for Ethiopian organizations. Ethiopian income tax, pension and calendar built in. Works offline, in Amharic and English.",
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
      <JsonLd data={softwareApplicationJsonLd(locale)} />
      <LandingContent />
    </>
  );
}
