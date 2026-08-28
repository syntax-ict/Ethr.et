"use client";

import { DetailPageSkeleton } from "@/components/patterns/PageSkeleton";
import { useT } from "@/lib/i18n/useT";

/** Profile is a detail view with tabs. */
export default function Loading() {
  const { t } = useT();
  return <DetailPageSkeleton label={t("common.loading", "Loading")} />;
}
