import type { Metadata } from "next";
import { FaqContent } from "./faq-content";
import { marketingMetadata } from "../page-metadata";
import { JsonLd } from "@/components/marketing/json-ld";
import { faqJsonLd } from "@/components/marketing/structured-data";

export async function generateMetadata({
  params,
}: {
  params: Promise<{ locale: string }>;
}): Promise<Metadata> {
  const { locale } = await params;

  return marketingMetadata(locale, "/faq", "faq", {
    title: "FAQ",
    description:
      "Answers to common questions about ETHR — the trial, Ethiopian payroll and calendar, offline support, security, and hosting.",
  });
}

export default async function FaqPage({
  params,
}: {
  params: Promise<{ locale: string }>;
}) {
  const { locale } = await params;

  return (
    <>
      {/* Every question here is rendered on the page below, from the same list
          — see `faq-items.ts`. Structured data that describes something the
          visitor cannot see is a manual-action risk, not a ranking trick. */}
      <JsonLd data={faqJsonLd(locale)} />
      <FaqContent />
    </>
  );
}
