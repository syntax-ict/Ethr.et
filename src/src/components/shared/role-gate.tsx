"use client";

import { ShieldAlert } from "lucide-react";
import { Button } from "@/components/ui/button";
import Link from "next/link";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useT } from "@/lib/i18n/useT";

interface RoleGateProps {
  minRole?: string;
  allowedRoles?: string[];
  /**
   * Grants access to anyone holding at least one of these `can.*` flags,
   * regardless of role level — for personas assembled from a permission
   * rather than a role tier (e.g. a Regional Manager view granted via
   * `dashboard.regional`, which a custom role could hold without meeting any
   * `minRole` threshold).
   */
  anyPermission?: Array<keyof ReturnType<typeof usePermissions>["can"]>;
  children: React.ReactNode;
}

export function RoleGate({
  minRole,
  allowedRoles,
  anyPermission,
  children,
}: RoleGateProps) {
  const { isAtLeast, hasRole, can, role } = usePermissions();
  const { t } = useT();

  const allowed = anyPermission
    ? anyPermission.some((flag) => can[flag])
    : minRole
      ? isAtLeast(minRole)
      : allowedRoles
        ? hasRole(...allowedRoles)
        : true;

  if (!allowed) {
    return (
      // data-testid so the E2E audit can assert this screen is *absent*. A
      // denial is fully accessible and perfectly contrasted, so axe passes it
      // and a mis-roled route reports green while auditing nothing — how the
      // /admin* routes stayed "green" for months while being visited as a
      // tenant admin. Matching on the copy instead would break under `am`.
      <div
        data-testid="role-gate-denied"
        className="flex flex-col items-center justify-center py-20 text-center"
      >
        <div className="flex h-16 w-16 items-center justify-center rounded-full bg-destructive/10">
          <ShieldAlert className="h-8 w-8 text-destructive" />
        </div>
        <h2 className="mt-4 text-xl font-semibold text-foreground">
          {t("role_gate.denied_title", "Access Denied")}
        </h2>
        <p className="mt-2 max-w-sm text-sm text-muted-foreground">
          {t(
            "role_gate.denied_body",
            "You don't have permission to access this page. Your role (:role) does not have the required access level.",
            { role: role.replace(/_/g, " ") },
          )}
        </p>
        <Button className="mt-6" asChild>
          <Link href="/dashboard">
            {t("role_gate.back_to_dashboard", "Back to Dashboard")}
          </Link>
        </Button>
      </div>
    );
  }

  return <>{children}</>;
}
