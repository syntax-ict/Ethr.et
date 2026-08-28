"use client";

import { ListPageSkeleton } from "@/components/patterns/PageSkeleton";
import { useT } from "@/lib/i18n/useT";

/** Notification feed. */
export default function Loading() {
  const { t } = useT();
  return (
    <ListPageSkeleton
      rows={10}
      columns={3}
      label={t("common.loading", "Loading")}
    />
  );
}
