import type { Metadata } from "next";
import { marketingMetadata } from "../page-metadata";
import { JsonLd } from "@/components/marketing/json-ld";
import { SolutionPage } from "@/components/marketing/solution-page";
import { breadcrumbJsonLd } from "@/components/marketing/structured-data";

const TITLE = "Leave Management Software for Ethiopia";

export async function generateMetadata({
  params,
}: {
  params: Promise<{ locale: string }>;
}): Promise<Metadata> {
  const { locale } = await params;

  return marketingMetadata(locale, "/leave-management", "leave_management", {
    title: TITLE,
    description:
      "Leave requests, approvals and balances for Ethiopian organizations, with monthly accrual, carry-forward and Ethiopian public holidays.",
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
          "/leave-management",
          "leave_management",
          TITLE,
        )}
      />
      <SolutionPage id="leave_management" />
    </>
  );
}
