"use client";

import { ListPageSkeleton } from "@/components/patterns/PageSkeleton";
import { useT } from "@/lib/i18n/useT";

/** Shift definitions table. */
export default function Loading() {
  const { t } = useT();
  return (
    <ListPageSkeleton
      rows={8}
      columns={5}
      label={t("common.loading", "Loading")}
    />
  );
}
