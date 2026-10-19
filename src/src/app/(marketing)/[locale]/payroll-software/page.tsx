import type { Metadata } from "next";
import { marketingMetadata } from "../page-metadata";
import { JsonLd } from "@/components/marketing/json-ld";
import { SolutionPage } from "@/components/marketing/solution-page";
import { breadcrumbJsonLd } from "@/components/marketing/structured-data";

const TITLE = "Payroll Software for Ethiopia";

export async function generateMetadata({
  params,
}: {
  params: Promise<{ locale: string }>;
}): Promise<Metadata> {
  const { locale } = await params;

  return marketingMetadata(locale, "/payroll-software", "payroll_software", {
    title: TITLE,
    description:
      "Payroll software for Ethiopian organizations: income tax under Proclamation 1395/2025, 7% and 11% pension, loan and cost-sharing deductions, and payslips on every phone.",
  });
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
          "/payroll-software",
          "payroll_software",
          TITLE,
        )}
      />
      <SolutionPage id="payroll_software" />
    </>
  );
}
