import type { Metadata } from "next";
import { LegalDocumentView } from "@/components/marketing/legal-document";
import { privacyEn } from "@/lib/legal/documents";

export const metadata: Metadata = {
  title: "Privacy Policy",
  description:
    "What ETHR collects, why, and what it does not collect — including no web analytics, no session recording, and no tracking cookies.",
  openGraph: {
    title: "ETHR — Privacy Policy",
    description:
      "How ETHR handles personal data, written against what the software actually does.",
  },
};

export default function PrivacyPage() {
  return <LegalDocumentView document={privacyEn} />;
}
