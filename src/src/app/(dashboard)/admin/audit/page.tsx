"use client";

import { RoleGate } from "@/components/shared/role-gate";
import { AuditLogExplorer } from "@/components/shared/audit-log-explorer";
import { useT } from "@/lib/i18n/useT";

/**
 * Platform-wide audit trail, super admin only.
 *
 * The tenant-scoped view at /settings/audit-logs cannot show this: impersonation,
 * tenant suspension and platform bank-account changes are recorded outside any
 * one tenant's scope, so without this page the actions with the largest blast
 * radius were the only ones nobody could review.
 */
export default function PlatformAuditLogPage() {
  const { t } = useT();

  return (
    <RoleGate minRole="super_admin">
      <AuditLogExplorer
        endpoint="/admin/audit"
        queryKey="admin-audit"
        title={t("platform_audit_page.title", "Platform Audit Log")}
        description={t(
          "platform_audit_page.description",
          "Every audited action across all tenants",
        )}
        exportPrefix="platform-audit-log"
      />
    </RoleGate>
  );
}
