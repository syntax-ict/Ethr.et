"use client";

import { ListPageSkeleton } from "@/components/patterns/PageSkeleton";
import { useT } from "@/lib/i18n/useT";

/** Announcement list. */
export default function Loading() {
  const { t } = useT();
  return (
    <ListPageSkeleton
      rows={6}
      columns={4}
      label={t("common.loading", "Loading")}
    />
  );
}
