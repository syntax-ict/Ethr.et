import type { Metadata } from "next";
import { PricingContent } from "./pricing-content";

export const metadata: Metadata = {
  title: "Pricing",
  description:
    "Simple, transparent pricing for ETHR. Start free, upgrade when you grow.",
};

export default function PricingPage() {
  return <PricingContent />;
}
