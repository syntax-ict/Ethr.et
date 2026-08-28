import type { Metadata } from "next";
import { FaqContent } from "./faq-content";

export const metadata: Metadata = {
  title: "FAQ",
  description:
    "Answers to common questions about ETHR — the Ethiopian Workforce Operating System. Trial, pricing, features, security, and more.",
  openGraph: {
    title: "ETHR — Frequently Asked Questions",
    description:
      "Everything you need to know about ETHR: the 6-month trial, Ethiopian payroll and calendar, offline support, security, and hosting.",
  },
};

export default function FaqPage() {
  return <FaqContent />;
}
