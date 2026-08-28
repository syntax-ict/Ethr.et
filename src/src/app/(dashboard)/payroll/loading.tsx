"use client";

import { ListPageSkeleton } from "@/components/patterns/PageSkeleton";
import { useT } from "@/lib/i18n/useT";

/** Payroll runs list. */
export default function Loading() {
  const { t } = useT();
  return (
    <ListPageSkeleton
      rows={8}
      columns={6}
      label={t("common.loading", "Loading")}
    />
  );
}
