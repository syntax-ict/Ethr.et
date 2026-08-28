"use client";

import { DetailPageSkeleton } from "@/components/patterns/PageSkeleton";
import { useT } from "@/lib/i18n/useT";

/** Billing is a plan/invoice detail view. */
export default function Loading() {
  const { t } = useT();
  return <DetailPageSkeleton label={t("common.loading", "Loading")} />;
}
