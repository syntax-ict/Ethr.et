import type { Metadata } from "next";
import { FeaturesContent } from "./features-content";
import { marketingMetadata } from "../page-metadata";

export async function generateMetadata({
  params,
}: {
  params: Promise<{ locale: string }>;
}): Promise<Metadata> {
  const { locale } = await params;

  return marketingMetadata(locale, "/features", "features", {
    title: "Features",
    description:
      "Explore ETHR features — attendance, payroll, leave, offline-first, bilingual, and more.",
  });
}

export default function FeaturesPage() {
  return <FeaturesContent />;
}
