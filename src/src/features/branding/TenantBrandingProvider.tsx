"use client";

import { useEffect } from "react";
import { useCurrentTenant } from "@/features/auth/api";
import { parseHex, readableInkOn, tint } from "@/lib/utils/color";

/**
 * Reads the current tenant from /auth/me and applies its saved brand colours
 * to the theme's custom properties on <html>.
 *
 * Until 2026-10-01 this set `--primary` (and `--secondary`, `--accent`) to an
 * HSL triplet. Neither half worked: globals.css builds `--color-primary` from
 * `--interactive-primary`, not `--primary` (its own comment says the @theme
 * chain through `--primary` is broken), and its tokens are hex colours, so a
 * bare "210 50% 40%" would not have been a colour anyway. Tenant branding
 * changed nothing on screen — the settings page saved it and previewed it.
 *
 * Now: the primary colour drives `--interactive-primary` (with a matching
 * focus ring and a lighter hover tone) and its text colour is chosen for
 * contrast, because a tenant may pick any hex — a yellow under white text is
 * unreadable. The accent colour drives `--brand-accent` the same way.
 * `secondary_color` is not applied: in this design system "secondary" is a
 * near-white surface, and painting a brand colour across every card would be
 * wrong. An inline style on <html> outranks both the light and dark themes,
 * so a brand colour holds in either.
 */
const BRAND_PROPERTIES = [
  "--interactive-primary",
  "--interactive-focus",
  "--interactive-hover",
  "--color-primary-foreground",
  "--brand-accent",
  "--brand-accent-foreground",
] as const;

export function brandProperties(theme: {
  primary_color?: string | null;
  accent_color?: string | null;
}): Partial<Record<(typeof BRAND_PROPERTIES)[number], string>> {
  const out: Partial<Record<(typeof BRAND_PROPERTIES)[number], string>> = {};

  const primary = theme.primary_color ?? "";
  if (parseHex(primary)) {
    out["--interactive-primary"] = primary;
    out["--interactive-focus"] = primary;
    out["--interactive-hover"] = tint(primary, 0.25) ?? primary;
    out["--color-primary-foreground"] = readableInkOn(primary);
  }

  const accent = theme.accent_color ?? "";
  if (parseHex(accent)) {
    out["--brand-accent"] = accent;
    out["--brand-accent-foreground"] = readableInkOn(accent);
  }

  return out;
}

export function TenantBrandingProvider({
  children,
}: {
  children: React.ReactNode;
}) {
  const { data: tenant } = useCurrentTenant();

  useEffect(() => {
    if (typeof document === "undefined") return;
    const root = document.documentElement;

    // Reset first so switching tenants doesn't leave stale colours.
    for (const property of BRAND_PROPERTIES) {
      root.style.removeProperty(property);
    }

    const theme = tenant?.theme;
    if (!theme) return;

    for (const [property, value] of Object.entries(brandProperties(theme))) {
      root.style.setProperty(property, value);
    }
  }, [tenant?.theme]);

  return <>{children}</>;
}

/**
 * Small header component showing the tenant logo (or a fallback initial)
 * plus the tenant name. Drop into any header/sidebar.
 */
export function TenantLogoBadge({
  size = "md",
  showName = true,
}: {
  size?: "sm" | "md";
  showName?: boolean;
}) {
  const { data: tenant } = useCurrentTenant();

  const boxCls =
    size === "sm" ? "h-7 w-7 rounded-md text-xs" : "h-8 w-8 rounded-lg text-sm";
  const initial = (tenant?.name ?? "E").trim().charAt(0).toUpperCase();

  const name = tenant?.name ?? "ETHR";

  // One line, truncated: at phone width a long organisation name wrapped over
  // its own logo and crowded the header icons (audit N34). The logo keeps its
  // size (shrink-0) and the full name stays available as the tooltip.
  return (
    <div className="flex min-w-0 items-center gap-2">
      <div
        className={`flex ${boxCls} shrink-0 items-center justify-center bg-primary text-primary-foreground font-bold overflow-hidden`}
      >
        {tenant?.logo_path ? (
          /* eslint-disable-next-line @next/next/no-img-element */
          <img
            src={tenant.logo_path}
            alt={tenant.name ?? "Logo"}
            className="h-full w-full object-cover"
            onError={(e) => {
              // Fallback to initial if logo URL is broken
              (e.currentTarget as HTMLImageElement).style.display = "none";
              (
                e.currentTarget.nextSibling as HTMLElement | null
              )?.classList.remove("hidden");
            }}
          />
        ) : null}
        <span className={tenant?.logo_path ? "hidden" : ""}>{initial}</span>
      </div>
      {showName && (
        <span
          title={name}
          className={`min-w-0 truncate ${
            size === "sm"
              ? "text-sm font-semibold"
              : "text-base font-bold tracking-tight"
          }`}
        >
          {name}
        </span>
      )}
    </div>
  );
}
