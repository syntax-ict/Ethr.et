"use client";

import { Building2 } from "lucide-react";
import { useT } from "@/lib/i18n/useT";
import { tenantAddress } from "@/lib/tenant-address";

/**
 * Replaces the manual "Organization subdomain" field on a tenant hostname —
 * the hostname already identifies the organization, so re-typing it would be
 * redundant and (per the login upgrade brief) must not look like an editable
 * field. Read-only by design.
 */
export function TenantHostIndicator({
  name,
  slug,
  loading,
}: {
  name: string | null;
  slug: string;
  loading: boolean;
}) {
  const { t } = useT();

  return (
    <div className="flex items-center gap-2.5 rounded-lg border border-border bg-muted/30 px-3 py-2.5">
      <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-primary-soft text-primary-on-soft">
        <Building2 className="h-4 w-4" aria-hidden="true" />
      </div>
      <div className="min-w-0">
        <p className="truncate text-sm font-medium text-foreground">
          {loading
            ? t("auth.org_loading", "Loading organization…")
            : (name ?? slug)}
        </p>
        <p className="truncate text-xs text-muted-foreground">
          {tenantAddress(slug)}
        </p>
      </div>
    </div>
  );
}
