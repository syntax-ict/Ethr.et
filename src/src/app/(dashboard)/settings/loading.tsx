"use client";

import { SettingsPageSkeleton } from "@/components/patterns/PageSkeleton";
import { useT } from "@/lib/i18n/useT";

/** Settings are stacked toggle panels. */
export default function Loading() {
  const { t } = useT();
  return <SettingsPageSkeleton label={t("common.loading", "Loading")} />;
}
