"use client";

import { AlertTriangle } from "lucide-react";
import { useT } from "@/lib/i18n/useT";

const ROOT_DOMAIN = process.env.NEXT_PUBLIC_ROOT_DOMAIN?.trim() || "ethr.et";

/**
 * Shown instead of the auth form when the hostname's tenant can't be signed
 * into — distinct from an authentication failure, per the login upgrade
 * brief. Backed by the same "tenant-not-found" / "tenant-inactive" RFC-7807
 * types ResolveTenant already returns for any request on the host, so this
 * is a display for a real server-verified state, not a client-side guess.
 */
export function TenantLookupErrorState({
  kind,
}: {
  kind: "not_found" | "inactive";
}) {
  const { t } = useT();

  const title =
    kind === "inactive"
      ? t("auth.tenant_inactive_title", "Organization unavailable")
      : t("auth.tenant_not_found_title", "Organization not found");

  const body =
    kind === "inactive"
      ? t(
          "auth.tenant_inactive_body",
          "This organization's account isn't currently active. Contact your administrator or ETHR support.",
        )
      : t(
          "auth.tenant_not_found_body",
          "We couldn't find an organization at this address. Check the link or contact your administrator.",
        );

  return (
    <div className="w-full max-w-sm mx-auto text-center">
      <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-xl bg-status-warning/10">
        <AlertTriangle
          className="h-6 w-6 text-status-warning"
          aria-hidden="true"
        />
      </div>
      <h1 className="mt-4 text-2xl font-bold text-foreground">{title}</h1>
      <p className="mt-2 text-sm text-muted-foreground">{body}</p>
      <a
        href={`https://${ROOT_DOMAIN}`}
        className="mt-6 inline-block text-sm font-medium text-primary hover:underline"
      >
        {t("auth.tenant_not_found_cta", "Go to :domain", {
          domain: ROOT_DOMAIN,
        })}
      </a>
    </div>
  );
}
