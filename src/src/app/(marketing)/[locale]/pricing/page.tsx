import type { Metadata } from "next";
import { PricingContent } from "./pricing-content";
import { marketingMetadata } from "../page-metadata";

export async function generateMetadata({
  params,
}: {
  params: Promise<{ locale: string }>;
}): Promise<Metadata> {
  const { locale } = await params;

  return marketingMetadata(locale, "/pricing", "pricing", {
    title: "Pricing for HR and Payroll Software in Ethiopia",
    description:
      "Simple, transparent pricing for ETHR, the HR and payroll software built for Ethiopian organizations. Start with a free trial and upgrade when you grow.",
  });
}

export default function PricingPage() {
  return <PricingContent />;
}
