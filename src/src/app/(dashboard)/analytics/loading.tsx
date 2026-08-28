"use client";

import { DashboardPageSkeleton } from "@/components/patterns/PageSkeleton";
import { useT } from "@/lib/i18n/useT";

/** Analytics is KPI tiles plus charts. */
export default function Loading() {
  const { t } = useT();
  return <DashboardPageSkeleton label={t("common.loading", "Loading")} />;
}
