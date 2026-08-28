"use client";

import { RoleGate } from "@/components/shared/role-gate";
import { AuditLogExplorer } from "@/components/shared/audit-log-explorer";
import { useT } from "@/lib/i18n/useT";

export default function AuditLogsPage() {
  const { t } = useT();

  return (
    <RoleGate minRole="tenant_admin">
      <AuditLogExplorer
        endpoint="/audit-logs"
        queryKey="audit-logs"
        title={t("audit_logs_page.title")}
        description={t("audit_logs_page.description")}
      />
    </RoleGate>
  );
}
