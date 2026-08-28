"use client";

import { DashboardPageSkeleton } from "@/components/patterns/PageSkeleton";
import { useT } from "@/lib/i18n/useT";

/** Reports open on a metrics summary. */
export default function Loading() {
  const { t } = useT();
  return <DashboardPageSkeleton label={t("common.loading", "Loading")} />;
}
