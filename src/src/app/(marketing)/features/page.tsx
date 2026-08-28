import type { Metadata } from "next";
import { FeaturesContent } from "./features-content";

export const metadata: Metadata = {
  title: "Features",
  description:
    "Explore ETHR features — attendance, payroll, leave, offline-first, bilingual, and more.",
};

export default function FeaturesPage() {
  return <FeaturesContent />;
}
