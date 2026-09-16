import type { Metadata } from "next";
import { LegalDocumentView } from "@/components/marketing/legal-document";
import { termsEn } from "@/lib/legal/documents";

export const metadata: Metadata = {
  title: "Terms of Service",
  description:
    "The agreement for using ETHR: accounts, the six-month trial, billing and dunning, availability, and what each side is responsible for.",
  openGraph: {
    title: "ETHR — Terms of Service",
    description:
      "Accounts, trial, billing, availability, and responsibilities.",
  },
};

export default function TermsPage() {
  return <LegalDocumentView document={termsEn} />;
}
