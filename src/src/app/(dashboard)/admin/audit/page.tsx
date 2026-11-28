"use client";

import Link from "next/link";
import { ShieldAlert } from "lucide-react";
import { RoleGate } from "@/components/shared/role-gate";
import { AuditLogExplorer } from "@/components/shared/audit-log-explorer";
import { EmptyState } from "@/components/shared/empty-state";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { useCurrentUser } from "@/features/auth/api";
import { useT } from "@/lib/i18n/useT";

/**
 * Platform-wide audit trail, super admin only.
 *
 * The tenant-scoped view at /settings/audit-logs cannot show this: impersonation,
 * tenant suspension and platform bank-account changes are recorded outside any
 * one tenant's scope, so without this page the actions with the largest blast
 * radius were the only ones nobody could review.
 *
 * It lists people from every organisation, so the API reads it only for an
 * operator with two-factor on. Without it the page says so and asks nothing,
 * instead of requesting what the API will refuse.
 */
export default function PlatformAuditLogPage() {
  const { t } = useT();
  const { data: me } = useCurrentUser();

  return (
    <RoleGate minRole="super_admin">
      {!me ? (
        <Skeleton className="h-64 w-full" />
      ) : !me.mfa_enabled ? (
        <EmptyState
          icon={ShieldAlert}
          title={t(
            "admin_console_page.mfa_required_title",
            "Two-factor authentication required",
          )}
          description={t(
            "admin_console_page.audit_needs_mfa",
            "The platform activity lists people from every organisation, so it needs two-factor authentication to read.",
          )}
          action={
            <Button variant="outline" size="sm" asChild>
              <Link href="/profile/security">
                {t("admin_console_page.enable_mfa", "Enable now")}
              </Link>
            </Button>
          }
        />
      ) : (
        <AuditLogExplorer
          endpoint="/admin/audit"
          queryKey="admin-audit"
          title={t("platform_audit_page.title", "Platform audit log")}
          exportPrefix="platform-audit-log"
          showOrganisation
        />
      )}
    </RoleGate>
  );
}
