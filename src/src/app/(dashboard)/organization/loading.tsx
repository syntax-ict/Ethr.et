"use client";

import { DetailPageSkeleton } from "@/components/patterns/PageSkeleton";
import { useT } from "@/lib/i18n/useT";

/** Org structure is a tree/detail view, not a flat table. */
export default function Loading() {
  const { t } = useT();
  return <DetailPageSkeleton label={t("common.loading", "Loading")} />;
}
