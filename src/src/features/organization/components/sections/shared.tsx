"use client";

import { Badge } from "@/components/ui/badge";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useT } from "@/lib/i18n/useT";

/**
 * Delete is a tenant-admin ability: `org.delete` is not in the seeded HR or
 * finance role, though both may open the organization page and create or
 * edit. Every delete button was shown to them and every delete refused with a
 * 403 (audit N55). The holidays page had the same defect and already gates this.
 */
export function useCanDeleteOrg(): boolean {
  return usePermissions().hasPermission("org.delete");
}

export function ActiveBadge({ active }: { active: boolean }) {
  const { t } = useT();
  return (
    <Badge
      variant="outline"
      className={active ? "border-0 bg-success-soft text-success-on-soft" : ""}
    >
      {active ? t("org.active", "Active") : t("org.inactive", "Inactive")}
    </Badge>
  );
}

/** `"none"` is the Select's empty choice; the API wants null. */
export function noneToNull(value: string): string | null {
  return value === "none" ? null : value;
}
