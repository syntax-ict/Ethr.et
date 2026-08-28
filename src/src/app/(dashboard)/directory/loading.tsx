"use client";

import { ListPageSkeleton } from "@/components/patterns/PageSkeleton";
import { useT } from "@/lib/i18n/useT";

/** Employee directory. */
export default function Loading() {
  const { t } = useT();
  return (
    <ListPageSkeleton
      rows={10}
      columns={4}
      label={t("common.loading", "Loading")}
    />
  );
}
