'use client';

import { useEffect } from 'react';
import { useCurrentTenant } from '@/features/auth/api';

/**
 * Reads the current tenant from /auth/me and applies its saved theme
 * (primary_color, secondary_color, accent_color) to CSS custom properties
 * on <html>. shadcn/ui reads --primary etc, so overriding at document root
 * cascades to every component.
 *
 * Colors are stored as hex strings (#RRGGBB). We convert to HSL because
 * globals.css declares --primary as HSL triplets (H S% L%). If a tenant
 * hasn't set a theme yet (theme is null/empty), we leave the defaults alone.
 */
export function TenantBrandingProvider({ children }: { children: React.ReactNode }) {
  const { data: tenant } = useCurrentTenant();

  useEffect(() => {
    if (typeof document === 'undefined') return;
    const root = document.documentElement;

    // Reset first so switching tenants doesn't leave stale colors
    root.style.removeProperty('--primary');
    root.style.removeProperty('--secondary');
    root.style.removeProperty('--accent');

    const theme = tenant?.theme;
    if (!theme) return;

    if (theme.primary_color) {
      const hsl = hexToHslString(theme.primary_color);
      if (hsl) root.style.setProperty('--primary', hsl);
    }
    if (theme.secondary_color) {
      const hsl = hexToHslString(theme.secondary_color);
      if (hsl) root.style.setProperty('--secondary', hsl);
    }
    if (theme.accent_color) {
      const hsl = hexToHslString(theme.accent_color);
      if (hsl) root.style.setProperty('--accent', hsl);
    }
  }, [tenant?.theme]);

  return <>{children}</>;
}

/** Convert #RRGGBB to "H S% L%" for CSS custom property assignment. */
function hexToHslString(hex: string): string | null {
  const m = hex.replace('#', '').match(/^([0-9a-fA-F]{6})$/);
  if (!m) return null;

  const r = parseInt(m[1].slice(0, 2), 16) / 255;
  const g = parseInt(m[1].slice(2, 4), 16) / 255;
  const b = parseInt(m[1].slice(4, 6), 16) / 255;

  const max = Math.max(r, g, b);
  const min = Math.min(r, g, b);
  const l = (max + min) / 2;

  let h = 0;
  let s = 0;

  if (max !== min) {
    const d = max - min;
    s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
    switch (max) {
      case r: h = (g - b) / d + (g < b ? 6 : 0); break;
      case g: h = (b - r) / d + 2; break;
      case b: h = (r - g) / d + 4; break;
    }
    h *= 60;
  }

  return `${Math.round(h)} ${Math.round(s * 100)}% ${Math.round(l * 100)}%`;
}

/**
 * Small header component showing the tenant logo (or a fallback initial)
 * plus the tenant name. Drop into any header/sidebar.
 */
export function TenantLogoBadge({
  size = 'md',
  showName = true,
}: {
  size?: 'sm' | 'md';
  showName?: boolean;
}) {
  const { data: tenant } = useCurrentTenant();

  const boxCls = size === 'sm'
    ? 'h-7 w-7 rounded-md text-xs'
    : 'h-8 w-8 rounded-lg text-sm';
  const initial = (tenant?.name ?? 'E').trim().charAt(0).toUpperCase();

  return (
    <div className="flex items-center gap-2">
      <div className={`flex ${boxCls} items-center justify-center bg-primary text-primary-foreground font-bold overflow-hidden`}>
        {tenant?.logo_path ? (
          /* eslint-disable-next-line @next/next/no-img-element */
          <img
            src={tenant.logo_path}
            alt={tenant.name ?? 'Logo'}
            className="h-full w-full object-cover"
            onError={(e) => {
              // Fallback to initial if logo URL is broken
              (e.currentTarget as HTMLImageElement).style.display = 'none';
              (e.currentTarget.nextSibling as HTMLElement | null)?.classList.remove('hidden');
            }}
          />
        ) : null}
        <span className={tenant?.logo_path ? 'hidden' : ''}>{initial}</span>
      </div>
      {showName && (
        <span className={size === 'sm' ? 'text-sm font-semibold' : 'text-base font-bold tracking-tight'}>
          {tenant?.name ?? 'ETHR'}
        </span>
      )}
    </div>
  );
}
