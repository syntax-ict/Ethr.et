"use client";

import { ListPageSkeleton } from "@/components/patterns/PageSkeleton";
import { useT } from "@/lib/i18n/useT";

/** Attendance log is a dense dated table. */
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
