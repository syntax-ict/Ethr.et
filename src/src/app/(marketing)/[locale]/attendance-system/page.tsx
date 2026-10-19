import type { Metadata } from "next";
import { marketingMetadata } from "../page-metadata";
import { JsonLd } from "@/components/marketing/json-ld";
import { SolutionPage } from "@/components/marketing/solution-page";
import { breadcrumbJsonLd } from "@/components/marketing/structured-data";

const TITLE = "Attendance System for Ethiopian Organizations";

export async function generateMetadata({
  params,
}: {
  params: Promise<{ locale: string }>;
}): Promise<Metadata> {
  const { locale } = await params;

  return marketingMetadata(locale, "/attendance-system", "attendance_system", {
    title: TITLE,
    description:
      "Employee attendance by phone, QR code, shared kiosk or web, with a location check, shifts and offline sync for Ethiopian workplaces.",
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
          "/attendance-system",
          "attendance_system",
          TITLE,
        )}
      />
      <SolutionPage id="attendance_system" />
    </>
  );
}
