import type { Metadata } from "next";
import { LegalDocumentView } from "@/components/marketing/legal-document";
import { privacyEn } from "@/lib/legal/documents";
import { marketingMetadata } from "../page-metadata";

export async function generateMetadata({
  params,
}: {
  params: Promise<{ locale: string }>;
}): Promise<Metadata> {
  const { locale } = await params;

  return marketingMetadata(locale, "/privacy", "privacy", {
    title: "Privacy Policy",
    description:
      "What ETHR collects, why, and what it does not collect — including no web analytics, no session recording, and no tracking cookies.",
  });
}

export default function PrivacyPage() {
  return <LegalDocumentView document={privacyEn} />;
}
