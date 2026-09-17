import type { Metadata } from "next";
import { FaqContent } from "./faq-content";
import { marketingMetadata } from "../page-metadata";

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

export default function FaqPage() {
  return <FaqContent />;
}
