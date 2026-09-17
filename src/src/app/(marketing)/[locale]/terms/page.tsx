import type { Metadata } from "next";
import { LegalDocumentView } from "@/components/marketing/legal-document";
import { termsEn } from "@/lib/legal/documents";
import { marketingMetadata } from "../page-metadata";

export async function generateMetadata({
  params,
}: {
  params: Promise<{ locale: string }>;
}): Promise<Metadata> {
  const { locale } = await params;

  return marketingMetadata(locale, "/terms", "terms", {
    title: "Terms of Service",
    description:
      "The agreement for using ETHR: accounts, the six-month trial, billing and dunning, availability, and what each side is responsible for.",
  });
}

export default function TermsPage() {
  return <LegalDocumentView document={termsEn} />;
}
