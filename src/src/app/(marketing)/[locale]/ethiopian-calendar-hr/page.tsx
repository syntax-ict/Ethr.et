import type { Metadata } from "next";
import { marketingMetadata } from "../page-metadata";
import { JsonLd } from "@/components/marketing/json-ld";
import { SolutionPage } from "@/components/marketing/solution-page";
import { breadcrumbJsonLd } from "@/components/marketing/structured-data";

const TITLE = "HR Software with the Ethiopian Calendar";

export async function generateMetadata({
  params,
}: {
  params: Promise<{ locale: string }>;
}): Promise<Metadata> {
  const { locale } = await params;

  return marketingMetadata(
    locale,
    "/ethiopian-calendar-hr",
    "ethiopian_calendar_hr",
    {
      title: TITLE,
      description:
        "HR and payroll that work in the Ethiopian calendar: 13 months, Pagume, Ethiopian holidays, and Amharic and English side by side.",
    },
  );
}

export default async function Page({
  params,
}: {
  params: Promise<{ locale: string }>;
}) {
  const { locale } = await params;

  return (
    <>
      <JsonLd
        data={breadcrumbJsonLd(
          locale,
          "/ethiopian-calendar-hr",
          "ethiopian_calendar_hr",
          TITLE,
        )}
      />
      <SolutionPage id="ethiopian_calendar_hr" />
    </>
  );
}
