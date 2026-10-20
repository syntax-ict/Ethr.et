"use client";

import { useState } from "react";
import { aaCorrected, readableInkOn } from "@/lib/utils/color";
import { Loader2, Palette, RotateCcw, Save } from "lucide-react";
import { toast } from "sonner";
import {
  useUpdateBranding,
  type BrandAssets,
  type BrandColorKey,
} from "@/features/settings/api";
import { useT } from "@/lib/i18n/useT";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { LogoUpload } from "@/features/settings/components/logo-upload";
import { BrandImageUpload } from "@/features/settings/components/brand-image-upload";
import { apiErrorMessage } from "@/lib/api/error-message";

/**
 * Tenant branding — the logo and its variants, and the theme colours.
 *
 * The read path already existed everywhere (`TenantBrandingProvider` applies
 * `tenant.theme` to CSS custom properties, `TenantLogoBadge` renders
 * `tenant.logo_path`) but `PUT /settings/branding` had no consumer, so no tenant
 * could ever set either. This is that write path.
 *
 * Every image is an upload (`LogoUpload`, `BrandImageUpload`): the typed logo
 * link this card offered until 2026-10-15 is retired, because the portal shows
 * only images ETHR re-encoded and serves itself.
 */

const DEFAULT_COLORS = {
  primary_color: "#0F4C75",
  secondary_color: "#3282B8",
  accent_color: "#E8A838",
} as const satisfies Record<BrandColorKey, string>;

type ColorKey = BrandColorKey;

export interface BrandingCardProps {
  /** The uploaded logo in effect. */
  uploadedLogoUrl?: string | null;
  /** The other uploaded images: the dark-background logo and the square mark. */
  assets?: Pick<BrandAssets, "logo_dark" | "mark"> | null;
  /** A colour cleared through `PUT /settings/branding` is stored as null. */
  theme?: Partial<Record<ColorKey, string | null>> | null;
}

export function BrandingCard({
  uploadedLogoUrl,
  assets,
  theme,
}: BrandingCardProps) {
  const { t } = useT();
  const updateBranding = useUpdateBranding();

  const [colors, setColors] = useState<Record<ColorKey, string>>({
    primary_color: theme?.primary_color ?? DEFAULT_COLORS.primary_color,
    secondary_color: theme?.secondary_color ?? DEFAULT_COLORS.secondary_color,
    accent_color: theme?.accent_color ?? DEFAULT_COLORS.accent_color,
  });

  // The hook refreshes /auth/me as well as the settings query: it carries the
  // theme TenantBrandingProvider applies, so without it the saved colors only
  // appear after a reload.
  function save() {
    updateBranding.mutate(
      { ...colors },
      {
        onSuccess: () =>
          toast.success(t("settings.branding_saved", "Branding saved")),
        onError: (err) =>
          toast.error(
            apiErrorMessage(
              err,
              t("settings.branding_save_failed", "Failed to save branding"),
            ),
          ),
      },
    );
  }

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

  // The primary and accent colours carry text (buttons, badges), so they are
  // shown as the page will show them: moved only as far as AA contrast
  // needs, and said so. The secondary colour carries none and is not moved.
  const corrected = (key: ColorKey) =>
    key === "secondary_color" ? null : aaCorrected(colors[key]);
  const surface = (key: ColorKey) => corrected(key)?.color ?? colors[key];
  const ink = (key: ColorKey) =>
    corrected(key)?.ink ?? readableInkOn(colors[key]);

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
        <LogoUpload uploadedLogoUrl={uploadedLogoUrl} />

        <BrandImageUpload
          kind="logo_dark"
          url={assets?.logo_dark?.url}
          title={t("settings.logo_dark_title", "Logo for dark backgrounds")}
          inUse={t(
            "settings.logo_dark_in_use",
            "Your dark-background logo is in use",
          )}
          help={t(
            "settings.logo_dark_help",
            "A version of your logo that reads on dark surfaces; a PNG with transparency works best. Shown when a visitor uses dark mode.",
          )}
          fileLabel={t("settings.logo_dark_file", "Dark-background logo file")}
          previewClassName="bg-foreground dark:bg-background"
        />

        <BrandImageUpload
          kind="mark"
          url={assets?.mark?.url}
          title={t("settings.mark_title", "Square mark")}
          inUse={t("settings.mark_in_use", "Your square mark is in use")}
          help={t(
            "settings.mark_help",
            "A square version of your mark for the browser tab, the home-screen icon and link previews. We centre it on a 512 px square with a safe margin.",
          )}
          fileLabel={t("settings.mark_file", "Square mark file")}
          previewClassName="rounded-xl"
        />

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
              {corrected(key)?.changed && (
                <p className="mt-1 text-xs text-muted-foreground" role="note">
                  {t(
                    "settings.color_adjusted",
                    "Shown as :hex where text sits on it, so the text reads at AA contrast.",
                    { hex: surface(key).toUpperCase() },
                  )}
                </p>
              )}
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
              className="inline-flex h-9 items-center rounded-md px-4 text-sm font-medium"
              style={{
                backgroundColor: surface("primary_color"),
                color: ink("primary_color"),
              }}
            >
              {t("common.save", "Save Changes")}
            </span>
            <span
              className="inline-flex h-9 items-center rounded-md px-4 text-sm font-medium"
              style={{
                backgroundColor: surface("secondary_color"),
                color: ink("secondary_color"),
              }}
            >
              {t("common.cancel", "Cancel")}
            </span>
            <span
              className="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold"
              style={{
                backgroundColor: surface("accent_color"),
                color: ink("accent_color"),
              }}
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
          <Button onClick={save} disabled={updateBranding.isPending}>
            {updateBranding.isPending ? (
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
