"use client";

import { useState } from "react";
import { Loader2, Palette, RotateCcw, Save } from "lucide-react";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { apiClient } from "@/api/client";
import { useT } from "@/lib/i18n/useT";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";

/**
 * Tenant branding — logo and theme colors.
 *
 * The read path already existed everywhere (`TenantBrandingProvider` applies
 * `tenant.theme` to CSS custom properties, `TenantLogoBadge` renders
 * `tenant.logo_path`) but `PUT /settings/branding` had no consumer, so no tenant
 * could ever set either. This is that write path.
 *
 * The logo is a URL, not an upload: `logo_path` is rendered directly as an
 * `<img src>` by every consumer (header, sidebar, mobile nav, kiosk), so a
 * storage key would render broken. An upload flow needs those consumers to
 * resolve a URL first — deliberately out of scope here.
 */

const DEFAULT_COLORS = {
  primary_color: "#0F4C75",
  secondary_color: "#3282B8",
  accent_color: "#E8A838",
} as const;

type ColorKey = keyof typeof DEFAULT_COLORS;

export interface BrandingCardProps {
  logoUrl?: string | null;
  theme?: Partial<Record<ColorKey, string>> | null;
}

export function BrandingCard({ logoUrl, theme }: BrandingCardProps) {
  const { t } = useT();
  const queryClient = useQueryClient();

  const [logo, setLogo] = useState(logoUrl ?? "");
  const [colors, setColors] = useState<Record<ColorKey, string>>({
    primary_color: theme?.primary_color ?? DEFAULT_COLORS.primary_color,
    secondary_color: theme?.secondary_color ?? DEFAULT_COLORS.secondary_color,
    accent_color: theme?.accent_color ?? DEFAULT_COLORS.accent_color,
  });
  const [logoBroken, setLogoBroken] = useState(false);

  const save = useMutation({
    mutationFn: async () => {
      const { data } = await apiClient.put("/settings/branding", {
        logo_url: logo.trim() === "" ? null : logo.trim(),
        ...colors,
      });
      return data;
    },
    onSuccess: () => {
      // /auth/me carries the theme TenantBrandingProvider applies, so it has to
      // be refetched too or the saved colors only appear after a reload.
      queryClient.invalidateQueries({ queryKey: ["settings"] });
      queryClient.invalidateQueries({ queryKey: ["auth", "me"] });
      toast.success(t("settings.branding_saved", "Branding saved"));
    },
    onError: () =>
      toast.error(
        t("settings.branding_save_failed", "Failed to save branding"),
      ),
  });

  function setColor(key: ColorKey, value: string) {
    setColors((prev) => ({ ...prev, [key]: value }));
  }

  function resetColors() {
    setColors({ ...DEFAULT_COLORS });
  }

  const colorFields: Array<{ key: ColorKey; label: string }> = [
    {
      key: "primary_color",
      label: t("settings.primary_color", "Primary color"),
    },
    {
      key: "secondary_color",
      label: t("settings.secondary_color", "Secondary color"),
    },
    { key: "accent_color", label: t("settings.accent_color", "Accent color") },
  ];

  return (
    <Card>
      <CardHeader>
        <div className="flex items-center gap-2">
          <Palette className="h-4 w-4 text-muted-foreground" />
          <CardTitle className="text-base">
            {t("settings.branding", "Branding")}
          </CardTitle>
        </div>
      </CardHeader>
      <CardContent className="space-y-6">
        <div className="grid gap-4 sm:grid-cols-[1fr_auto] sm:items-start">
          <div>
            <Label htmlFor="branding-logo">
              {t("settings.logo_url", "Logo URL")}
            </Label>
            <Input
              id="branding-logo"
              type="url"
              inputMode="url"
              placeholder="https://cdn.example.et/logo.png"
              value={logo}
              onChange={(e) => {
                setLogo(e.target.value);
                setLogoBroken(false);
              }}
              className="mt-1"
            />
            <p className="mt-1 text-xs text-muted-foreground">
              {t(
                "settings.logo_url_help",
                "Publicly reachable image URL. Shown in the sidebar, header, and kiosk screen. Leave empty to use the organization initial.",
              )}
            </p>
          </div>
          <div className="sm:mt-6">
            <div
              className="flex h-16 w-16 items-center justify-center overflow-hidden rounded-lg border bg-muted"
              aria-live="polite"
            >
              {logo.trim() !== "" && !logoBroken ? (
                /* eslint-disable-next-line @next/next/no-img-element */
                <img
                  src={logo}
                  alt={t("settings.logo_preview", "Logo preview")}
                  className="h-full w-full object-contain"
                  onError={() => setLogoBroken(true)}
                />
              ) : (
                <span className="px-1 text-center text-[10px] text-muted-foreground">
                  {logoBroken
                    ? t("settings.logo_unreachable", "Image not reachable")
                    : t("settings.no_logo", "No logo")}
                </span>
              )}
            </div>
          </div>
        </div>

        <div className="grid gap-4 sm:grid-cols-3">
          {colorFields.map(({ key, label }) => (
            <div key={key}>
              <Label htmlFor={`branding-${key}`}>{label}</Label>
              <div className="mt-1 flex items-center gap-2">
                <input
                  type="color"
                  // Distinct from the hex field's own label, so both controls
                  // stay individually addressable to a screen reader.
                  aria-label={t("settings.color_swatch", ":label swatch", {
                    label,
                  })}
                  value={colors[key]}
                  onChange={(e) => setColor(key, e.target.value)}
                  className="h-9 w-10 shrink-0 cursor-pointer rounded border bg-transparent p-0.5"
                />
                <Input
                  id={`branding-${key}`}
                  value={colors[key]}
                  onChange={(e) => setColor(key, e.target.value)}
                  className="font-mono uppercase"
                  maxLength={7}
                  spellCheck={false}
                />
              </div>
            </div>
          ))}
        </div>

        <div
          className="rounded-lg border p-4"
          style={{ borderColor: colors.primary_color }}
        >
          <p className="mb-3 text-xs font-medium uppercase tracking-wide text-muted-foreground">
            {t("settings.branding_preview", "Preview")}
          </p>
          <div className="flex flex-wrap items-center gap-2">
            <span
              className="inline-flex h-9 items-center rounded-md px-4 text-sm font-medium text-white"
              style={{ backgroundColor: colors.primary_color }}
            >
              {t("common.save", "Save Changes")}
            </span>
            <span
              className="inline-flex h-9 items-center rounded-md px-4 text-sm font-medium text-white"
              style={{ backgroundColor: colors.secondary_color }}
            >
              {t("common.cancel", "Cancel")}
            </span>
            <span
              className="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold text-white"
              style={{ backgroundColor: colors.accent_color }}
            >
              {t("settings.branding_badge_sample", "Badge")}
            </span>
          </div>
        </div>

        <div className="flex flex-wrap justify-end gap-2">
          <Button variant="outline" onClick={resetColors} type="button">
            <RotateCcw className="mr-2 h-4 w-4" />
            {t("settings.reset_colors", "Reset to defaults")}
          </Button>
          <Button onClick={() => save.mutate()} disabled={save.isPending}>
            {save.isPending ? (
              <Loader2 className="mr-2 h-4 w-4 animate-spin" />
            ) : (
              <Save className="mr-2 h-4 w-4" />
            )}
            {t("settings.save_branding", "Save branding")}
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}
