"use client";

import { ListPageSkeleton } from "@/components/patterns/PageSkeleton";
import { useT } from "@/lib/i18n/useT";

/** Employee index is a six-column paginated table. */
export default function Loading() {
  const { t } = useT();
  return (
    <ListPageSkeleton
      rows={10}
      columns={6}
      label={t("common.loading", "Loading")}
    />
  );
}
