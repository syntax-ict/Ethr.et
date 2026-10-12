"use client";

import Link from "next/link";
import { Building2 } from "lucide-react";
import { useT } from "@/lib/i18n/useT";

const ROOT_DOMAIN = process.env.NEXT_PUBLIC_ROOT_DOMAIN?.trim() || "ethr.et";

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
  logoUrl,
  address,
  changeHref,
}: {
  name: string | null;
  slug: string;
  loading: boolean;
  /** The organisation's logo, in place of the building icon. */
  logoUrl?: string | null;
  /** Shown under the name; defaults to the subdomain address. */
  address?: string;
  /** A way out for someone who followed the wrong organisation's link. */
  changeHref?: string;
}) {
  const { t } = useT();

  return (
    <div className="flex items-center gap-2.5 rounded-lg border border-border bg-muted/30 px-3 py-2.5">
      <div className="flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-md bg-primary-soft text-primary-on-soft">
        {logoUrl ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img
            src={logoUrl}
            alt=""
            className="h-full w-full bg-white object-contain p-0.5"
            referrerPolicy="no-referrer"
          />
        ) : (
          <Building2 className="h-4 w-4" aria-hidden="true" />
        )}
      </div>
      <div className="min-w-0">
        <p className="truncate text-sm font-medium text-foreground">
          {loading
            ? t("auth.org_loading", "Loading organization…")
            : (name ?? slug)}
        </p>
        <p className="truncate text-xs text-muted-foreground">
          {address ?? `${slug}.${ROOT_DOMAIN}`}
        </p>
      </div>
      {changeHref && (
        <Link
          href={changeHref}
          className="ml-auto shrink-0 text-xs font-medium text-primary hover:underline"
        >
          {t("auth.not_your_org", "Not yours?")}
        </Link>
      )}
    </div>
  );
}
