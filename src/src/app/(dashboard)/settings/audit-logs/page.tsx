"use client";

import { RoleGate } from "@/components/shared/role-gate";
import { AuditLogExplorer } from "@/components/shared/audit-log-explorer";
import { useT } from "@/lib/i18n/useT";
import { PageHeader } from "@/components/shared/page-header";
import { PlanFeatureNotice } from "@/components/shared/plan-feature-notice";
import { usePlanFeatures } from "@/features/auth/api";

export default function AuditLogsPage() {
  const { t } = useT();
  // Reading the audit log needs the plan's `audit_log` feature: without it
  // the page showed only a load error (N66).
  const hasAuditLog = usePlanFeatures().has("audit_log");

  return (
    <RoleGate anyPermission={["manageSettings"]}>
      {hasAuditLog ? (
        <AuditLogExplorer
          endpoint="/audit-logs"
          queryKey="audit-logs"
          title={t("audit_logs_page.title")}
          description={t("audit_logs_page.description")}
        />
      ) : (
        <div className="space-y-6">
          <PageHeader
            title={t("audit_logs_page.title")}
            description={t("audit_logs_page.description")}
          />
          <PlanFeatureNotice />
        </div>
      )}
    </RoleGate>
  );
}
