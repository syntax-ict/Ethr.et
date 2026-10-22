"use client";

import { useEffect } from "react";
import { useTheme } from "next-themes";
import { useCurrentTenant } from "@/features/auth/api";
import { aaCorrected, contrastRatio, tint } from "@/lib/utils/color";

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
 *
 * Since 2026-10-14 the colour that carries text is `aaCorrected()`: a brand
 * colour whose best ink still falls short of 4.5:1 is darkened or lightened
 * only as far as AA needs, and the settings screen says so beside the swatch.
 * The organisation's exact colour stays where no text sits on it.
 */
const BRAND_PROPERTIES = [
  "--interactive-primary",
  "--interactive-focus",
  "--interactive-hover",
  "--color-primary-foreground",
  "--brand-accent",
  "--brand-accent-foreground",
] as const;

/** The dark theme's page surface (`--surface-primary` under `.dark`). */
export const DARK_SURFACE = "#0f172a";
/**
 * What a brand colour must reach against the dark page in dark mode: 3:1,
 * WCAG's floor for a control's boundary and a focus ring (1.4.11).
 */
export const DARK_UI_CONTRAST = 3;

export function brandProperties(
  theme: {
    primary_color?: string | null;
    accent_color?: string | null;
  },
  mode: "light" | "dark" = "light",
): Partial<Record<(typeof BRAND_PROPERTIES)[number], string>> {
  const out: Partial<Record<(typeof BRAND_PROPERTIES)[number], string>> = {};

  // A brand colour can be fine on white and vanish on the dark page: navy
  // links, buttons and focus rings at 1.5:1. In dark mode a colour that
  // cannot reach 3:1 against the page is not applied, and ETHR's own dark
  // colour stays (FRONTEND_REDESIGN_PLAN.md, backlog 25).
  const visibleInMode = (hex: string) =>
    mode === "light" || contrastRatio(hex, DARK_SURFACE) >= DARK_UI_CONTRAST;

  const primary = aaCorrected(theme.primary_color ?? "");
  if (primary && visibleInMode(primary.color)) {
    out["--interactive-primary"] = primary.color;
    out["--interactive-focus"] = primary.color;
    out["--interactive-hover"] = tint(primary.color, 0.25) ?? primary.color;
    out["--color-primary-foreground"] = primary.ink;
  }

  const accent = aaCorrected(theme.accent_color ?? "");
  if (accent && visibleInMode(accent.color)) {
    out["--brand-accent"] = accent.color;
    out["--brand-accent-foreground"] = accent.ink;
  }

  return out;
}

/**
 * Applies an organisation's colours to the document and returns the undo.
 * For pages drawn before sign-in (the shared landing at ethr.et/{slug}),
 * which have no `/auth/me` to read the theme from.
 */
export function applyBrandToDocument(theme: {
  primary_color?: string | null;
  accent_color?: string | null;
}): () => void {
  if (typeof document === "undefined") return () => {};
  const root = document.documentElement;
  const mode = root.classList.contains("dark") ? "dark" : "light";
  const applied = Object.entries(brandProperties(theme, mode));
  for (const [property, value] of applied) {
    root.style.setProperty(property, value);
  }
  return () => {
    for (const [property] of applied) root.style.removeProperty(property);
  };
}

export function TenantBrandingProvider({
  children,
}: {
  children: React.ReactNode;
}) {
  const { data: tenant } = useCurrentTenant();
  const { resolvedTheme } = useTheme();

  useEffect(() => {
    if (typeof document === "undefined") return;
    const root = document.documentElement;

    // Reset first so switching tenants doesn't leave stale colours.
    for (const property of BRAND_PROPERTIES) {
      root.style.removeProperty(property);
    }

    const theme = tenant?.theme;
    // A person who chose high contrast keeps it: the brand colours are
    // inline styles on <html>, which beat the `.high-contrast` variables, so
    // applying them there overrode an accessibility choice.
    if (!theme || resolvedTheme === "high-contrast") return;

    const mode = resolvedTheme === "dark" ? "dark" : "light";
    for (const [property, value] of Object.entries(
      brandProperties(theme, mode),
    )) {
      root.style.setProperty(property, value);
    }
  }, [tenant?.theme, resolvedTheme]);

  return <>{children}</>;
}

/**
 * Small header component showing the tenant logo (or a fallback initial)
 * plus the tenant name. Drop into any header/sidebar.
 *
 * With a logo for dark backgrounds uploaded, it is the one shown on a dark
 * surface: the sidebar always (`surface="dark"`, its surface is dark in both
 * themes), and anywhere else in dark mode.
 */
export function TenantLogoBadge({
  size = "md",
  showName = true,
  surface,
}: {
  size?: "sm" | "md";
  showName?: boolean;
  surface?: "dark";
}) {
  const { data: tenant } = useCurrentTenant();
  const { resolvedTheme } = useTheme();

  const boxCls =
    size === "sm" ? "h-7 w-7 rounded-md text-xs" : "h-8 w-8 rounded-lg text-sm";
  const initial = (tenant?.name ?? "E").trim().charAt(0).toUpperCase();

  const name = tenant?.name ?? "ETHR";
  const onDark = surface === "dark" || resolvedTheme === "dark";
  const logo =
    (onDark ? tenant?.logo_dark_url : null) ?? tenant?.logo_path ?? null;

  // One line, truncated: at phone width a long organisation name wrapped over
  // its own logo and crowded the header icons (audit N34). The logo keeps its
  // size (shrink-0) and the full name stays available as the tooltip.
  return (
    <div className="flex min-w-0 items-center gap-2">
      <div
        className={`flex ${boxCls} shrink-0 items-center justify-center bg-primary text-primary-foreground font-bold overflow-hidden`}
      >
        {logo ? (
          /* eslint-disable-next-line @next/next/no-img-element */
          <img
            src={logo}
            alt={tenant?.name ?? "Logo"}
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
        <span className={logo ? "hidden" : ""}>{initial}</span>
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
