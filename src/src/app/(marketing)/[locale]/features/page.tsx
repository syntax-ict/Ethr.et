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
    title: "Features: Payroll, Attendance and Leave for Ethiopia",
    description:
      "Payroll with Ethiopian income tax and pension, attendance by phone, QR code and kiosk, leave balances, the Ethiopian calendar, offline use and Amharic.",
  });
}

export default function FeaturesPage() {
  return <FeaturesContent />;
}
