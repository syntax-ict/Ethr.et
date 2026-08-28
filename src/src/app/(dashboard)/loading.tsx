"use client";

import { DashboardPageSkeleton } from "@/components/patterns/PageSkeleton";
import { useT } from "@/lib/i18n/useT";

/**
 * Fallback for the `(dashboard)` layout's `children` slot.
 *
 * It must render content-area chrome only. The previous version drew a full
 * `min-h-screen` shell — sidebar, header, the lot — which Next.js then mounted
 * *inside* the real layout's `<main>`, painting a second ghost sidebar and
 * header below the working ones on every navigation.
 */
export default function DashboardLoading() {
  const { t } = useT();
  return <DashboardPageSkeleton label={t("common.loading", "Loading")} />;
}
