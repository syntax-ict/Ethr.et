"use client";

import { useState } from "react";
import {
  Eye,
  Globe,
  LayoutTemplate,
  Loader2,
  Save,
  Upload,
  ExternalLink,
} from "lucide-react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";

import { apiClient } from "@/api/client";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Switch } from "@/components/ui/switch";
import { Textarea } from "@/components/ui/textarea";
import { FormField } from "@/components/patterns/FormField";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import { SettingRow } from "@/components/patterns/SettingRow";
import { RoleGate } from "@/components/shared/role-gate";
import { useT } from "@/lib/i18n/useT";

import { SectionsManager } from "./sections-manager";

/**
 * The tenant's public landing page settings.
 *
 * The publish switch is the only control here with a consequence outside the
 * application, so it is treated differently from the rest: it sits in its own
 * card at the top, it says in words what turning it on means, and it is the
 * only field that saves on its own rather than with the form. An administrator
 * should never put their organisation on the public internet as a side effect
 * of fixing a typo.
 */

const SOCIAL_PLATFORMS = [
  "facebook",
  "instagram",
  "linkedin",
  "x",
  "youtube",
  "telegram",
  "tiktok",
] as const;

interface PublicPage {
  url: string;
  is_published: boolean;
  is_indexable: boolean;
  headline: string | null;
  description: string | null;
  contact_email: string | null;
  contact_phone: string | null;
  address_line: string | null;
  city: string | null;
  region: string | null;
  website_url: string | null;
  social_links: Record<string, string>;
  meta_description: string | null;
  has_hero_image: boolean;
  has_public_logo: boolean;
  published_at: string | null;
  /** null means the classic layout — a real choice, not a missing value. */
  preset: string | null;
  /** What this organisation would get if it never chose. */
  preset_default: string;
  /** Presets this tenant may select. `government` is absent unless the
   *  platform has verified them, so the UI never offers a choice the API
   *  will refuse. */
  available_presets: string[];
  is_suspended: boolean;
}

type Draft = Omit<
  PublicPage,
  | "url"
  | "has_hero_image"
  | "has_public_logo"
  | "published_at"
  | "preset_default"
  | "available_presets"
  | "is_suspended"
>;

function toDraft(page: PublicPage): Draft {
  return {
    is_published: page.is_published,
    is_indexable: page.is_indexable,
    headline: page.headline ?? "",
    description: page.description ?? "",
    contact_email: page.contact_email ?? "",
    contact_phone: page.contact_phone ?? "",
    address_line: page.address_line ?? "",
    city: page.city ?? "",
    region: page.region ?? "",
    website_url: page.website_url ?? "",
    social_links: page.social_links ?? {},
    meta_description: page.meta_description ?? "",
    preset: page.preset,
  };
}

/**
 * The label for a preset.
 *
 * A literal switch rather than an interpolated key, and deliberately so: the
 * i18n coverage gate only sees string-literal keys, so `t(`…${preset}`)` would
 * compile, render, and go unchecked in both locales for ever. The same reason
 * organization-card.tsx spells its organisation types out one by one.
 */
function presetLabel(
  t: (key: string, fallback?: string) => string,
  preset: string,
): string {
  switch (preset) {
    case "government":
      return t("settings.public_page.preset_government", "Government office");
    case "university":
      return t(
        "settings.public_page.preset_university",
        "University or school",
      );
    case "hospital":
      return t("settings.public_page.preset_hospital", "Hospital or clinic");
    case "ngo":
      return t("settings.public_page.preset_ngo", "NGO");
    case "bank":
      return t("settings.public_page.preset_bank", "Bank or finance");
    case "manufacturing":
      return t("settings.public_page.preset_manufacturing", "Manufacturing");
    case "hotel":
      return t("settings.public_page.preset_hotel", "Hotel or hospitality");
    default:
      return t("settings.public_page.preset_general", "General business");
  }
}

export default function PublicPageSettings() {
  const { t } = useT();

  const query = useQuery({
    queryKey: ["settings", "public-page"],
    queryFn: async () => {
      const { data } = await apiClient.get<{ public_page: PublicPage }>(
        "/settings/public-page",
      );
      return data.public_page;
    },
  });

  return (
    <RoleGate minRole="tenant_admin">
      <div className="space-y-6">
        <QueryBoundary query={query}>
          {/*
            Keyed on the fields the server owns, so a change made elsewhere —
            a publish toggle, another administrator saving — remounts the form
            with fresh values. Deliberately not keyed on the whole response:
            every refetch returns a new object, and remounting on each one
            would steal focus mid-typing. Nor synced from props in an effect,
            which is the cascading-render pattern React now lints against.
          */}
          {(page) => (
            <PublicPageForm
              key={`${page.is_published}:${page.has_public_logo}:${page.has_hero_image}:${page.preset}`}
              page={page}
              t={t}
            />
          )}
        </QueryBoundary>
      </div>
    </RoleGate>
  );
}

function PublicPageForm({
  page,
  t,
}: {
  page: PublicPage;
  t: (
    key: string,
    fallback?: string,
    replacements?: Record<string, string>,
  ) => string;
}) {
  const queryClient = useQueryClient();
  const [draft, setDraft] = useState<Draft>(() => toDraft(page));
  const [errors, setErrors] = useState<Record<string, string[]>>({});

  /**
   * Ask the API for a short-lived preview URL and open it.
   *
   * Minted server-side rather than built here: the signature is what
   * authorises the page, and a URL the browser could assemble would not be a
   * signature at all.
   */
  const preview = useMutation({
    mutationFn: async () => {
      const { data } = await apiClient.post<{ url: string }>(
        "/settings/public-page/preview-url",
      );
      return data.url;
    },
    onSuccess: (url) => {
      window.open(url, "_blank", "noopener,noreferrer");
    },
    onError: () => {
      toast.error(
        t("settings.public_page.preview_failed", "Could not open the preview."),
      );
    },
  });

  const save = useMutation({
    mutationFn: async (changes: Partial<Draft>) => {
      const { data } = await apiClient.put("/settings/public-page", changes);
      return data;
    },
    onSuccess: () => {
      setErrors({});
      queryClient.invalidateQueries({ queryKey: ["settings", "public-page"] });
      toast.success(t("settings.public_page.saved", "Public page saved"));
    },
    onError: (error: {
      response?: { data?: { errors?: Record<string, string[]> } };
    }) => {
      const validation = error.response?.data?.errors;
      if (validation) {
        setErrors(validation);
        return;
      }
      toast.error(
        t("settings.public_page.save_failed", "Couldn't save the public page"),
      );
    },
  });

  const publish = useMutation({
    mutationFn: async (isPublished: boolean) => {
      const { data } = await apiClient.put("/settings/public-page", {
        is_published: isPublished,
      });
      return data;
    },
    onSuccess: (_data, isPublished) => {
      queryClient.invalidateQueries({ queryKey: ["settings", "public-page"] });
      toast.success(
        isPublished
          ? t("settings.public_page.published", "Your page is now public")
          : t(
              "settings.public_page.unpublished",
              "Your page is no longer public",
            ),
      );
    },
    onError: () =>
      toast.error(
        t("settings.public_page.publish_failed", "Couldn't change visibility"),
      ),
  });

  const field = (key: keyof Draft) => errors[key]?.[0];

  return (
    <>
      {/*
        The layout card.

        Separate from the content cards because choosing a layout is a
        different kind of decision from writing copy: it changes what sections
        the page has, and switching adds the new layout's blocks. Those arrive
        hidden, so nothing appears publicly until someone reveals it.
      */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <LayoutTemplate className="h-4 w-4" />
            {t("settings.public_page.layout", "Layout")}
          </CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          <FormField
            id="public-page-preset"
            label={t("settings.public_page.preset", "Page layout")}
            hint={t(
              "settings.public_page.preset_hint",
              "Choose the layout that suits your organization. Switching adds the sections that layout needs, hidden until you fill them in.",
            )}
            error={field("preset")}
          >
            <select
              id="public-page-preset"
              className="h-9 w-full rounded-md border border-border bg-background px-3 text-sm"
              value={draft.preset ?? ""}
              onChange={(e) =>
                setDraft((d) => ({
                  ...d,
                  preset: e.target.value === "" ? null : e.target.value,
                }))
              }
            >
              <option value="">
                {t("settings.public_page.preset_classic", "Simple (current)")}
              </option>
              {page.available_presets.map((preset) => (
                <option key={preset} value={preset}>
                  {presetLabel(t, preset)}
                  {preset === page.preset_default
                    ? ` — ${t("settings.public_page.preset_recommended", "recommended")}`
                    : ""}
                </option>
              ))}
            </select>
          </FormField>

          {/*
            Said out loud rather than left as an absent option. An
            administrator whose organisation is a public body should know the
            layout exists and what it takes to use it, not wonder why a
            colleague's page looks different from theirs.
          */}
          {!page.available_presets.includes("government") && (
            <p className="text-xs text-muted-foreground">
              {t(
                "settings.public_page.preset_government_locked",
                "The government layout is available to verified government organizations. Contact ETHR support to request verification.",
              )}
            </p>
          )}

          {page.is_suspended && (
            <p className="rounded-md bg-destructive/10 p-3 text-sm text-destructive">
              {t(
                "settings.public_page.suspended",
                "This page has been suspended by ETHR and is not reachable. Contact support.",
              )}
            </p>
          )}

          <div className="flex items-center gap-2">
            <Button
              type="button"
              variant="outline"
              size="sm"
              disabled={preview.isPending}
              onClick={() => preview.mutate()}
            >
              {preview.isPending ? (
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
              ) : (
                <Eye className="mr-2 h-4 w-4" />
              )}
              {t("settings.public_page.preview_draft", "Preview")}
            </Button>
            <span className="text-xs text-muted-foreground">
              {t(
                "settings.public_page.preview_hint",
                "Opens a private preview link that expires in 15 minutes.",
              )}
            </span>
          </div>
        </CardContent>
      </Card>

      {/*
        Only once a layout is chosen. The classic page has no sections, so a
        manager there would offer to arrange something that does not exist.
      */}
      {page.preset !== null && <SectionsManager t={t} />}

      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Globe className="h-4 w-4" />
            {t("settings.public_page.title", "Public page")}
          </CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          <SettingRow
            id="public-page-published"
            title={t("settings.public_page.publish", "Publish this page")}
            description={
              draft.is_published
                ? t(
                    "settings.public_page.publish_on_hint",
                    "Anyone on the internet can see this page at your address.",
                  )
                : t(
                    "settings.public_page.publish_off_hint",
                    "Your page is private. Turning this on makes it visible to anyone on the internet.",
                  )
            }
          >
            <Switch
              checked={draft.is_published}
              disabled={publish.isPending}
              onCheckedChange={(checked) => {
                setDraft((d) => ({ ...d, is_published: checked }));
                publish.mutate(checked);
              }}
            />
          </SettingRow>

          <SettingRow
            id="public-page-indexable"
            title={t(
              "settings.public_page.indexable",
              "Allow search engines to list this page",
            )}
            description={t(
              "settings.public_page.indexable_hint",
              "Turn this off to keep a published page out of search results. People with the link can still open it.",
            )}
          >
            <Switch
              checked={draft.is_indexable}
              onCheckedChange={(checked) =>
                setDraft((d) => ({ ...d, is_indexable: checked }))
              }
            />
          </SettingRow>

          <div className="flex flex-wrap items-center gap-2 rounded-md border border-border-default bg-surface-secondary p-3 text-sm">
            <span className="text-text-secondary">
              {t("settings.public_page.address", "Address")}
            </span>
            <code className="font-mono">{page.url}</code>
            {draft.is_published && (
              <a
                className="inline-flex items-center gap-1 text-interactive-primary hover:underline"
                href={page.url}
                target="_blank"
                rel="noopener noreferrer"
              >
                {t("settings.public_page.preview", "Open")}
                <ExternalLink className="h-3 w-3" />
              </a>
            )}
          </div>

          {!page.has_public_logo && (
            /*
             * The branding logo has always been stored as a free-text URL, so
             * an existing tenant's value may be an external link. Those are
             * never rendered publicly — see TenantPublicAsset — and without
             * this notice an administrator would simply watch their logo fail
             * to appear with no explanation anywhere.
             */
            <p className="rounded-md border border-warning-edge bg-warning-soft p-3 text-sm text-warning-on-soft">
              {t(
                "settings.public_page.logo_needs_upload",
                "Upload a logo file to show it on your public page. A logo added as a web address is used inside ETHR only.",
              )}
            </p>
          )}

          <ImageUpload
            label={t("settings.public_page.logo", "Logo")}
            endpoint="/settings/branding/logo"
            queryKey={["settings", "public-page"]}
            t={t}
          />

          <ImageUpload
            label={t("settings.public_page.hero", "Cover image")}
            endpoint="/settings/public-page/hero"
            queryKey={["settings", "public-page"]}
            t={t}
          />
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>
            {t("settings.public_page.content", "Page content")}
          </CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          <FormField
            id="headline"
            label={t("settings.public_page.headline", "Headline")}
            hint={t(
              "settings.public_page.headline_hint",
              "One short line under your organization's name.",
            )}
            error={field("headline")}
          >
            <Input
              value={draft.headline ?? ""}
              maxLength={160}
              onChange={(e) =>
                setDraft((d) => ({ ...d, headline: e.target.value }))
              }
            />
          </FormField>

          <FormField
            id="description"
            label={t("settings.public_page.description", "About")}
            error={field("description")}
          >
            <Textarea
              rows={6}
              value={draft.description ?? ""}
              maxLength={4000}
              onChange={(e) =>
                setDraft((d) => ({ ...d, description: e.target.value }))
              }
            />
          </FormField>

          <FormField
            id="meta_description"
            label={t(
              "settings.public_page.meta_description",
              "Search result summary",
            )}
            hint={t(
              "settings.public_page.meta_description_hint",
              "Shown under the title in search results. Leave blank to use the start of your About text.",
            )}
            error={field("meta_description")}
          >
            <Input
              value={draft.meta_description ?? ""}
              maxLength={320}
              onChange={(e) =>
                setDraft((d) => ({ ...d, meta_description: e.target.value }))
              }
            />
          </FormField>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>{t("settings.public_page.contact", "Contact")}</CardTitle>
        </CardHeader>
        <CardContent className="grid gap-4 sm:grid-cols-2">
          <FormField
            id="contact_phone"
            label={t("settings.public_page.phone", "Phone")}
            error={field("contact_phone")}
          >
            <Input
              value={draft.contact_phone ?? ""}
              onChange={(e) =>
                setDraft((d) => ({ ...d, contact_phone: e.target.value }))
              }
            />
          </FormField>

          <FormField
            id="contact_email"
            label={t("settings.public_page.email", "Email")}
            error={field("contact_email")}
          >
            <Input
              type="email"
              value={draft.contact_email ?? ""}
              onChange={(e) =>
                setDraft((d) => ({ ...d, contact_email: e.target.value }))
              }
            />
          </FormField>

          <FormField
            id="address_line"
            label={t("settings.public_page.address_line", "Street address")}
            error={field("address_line")}
          >
            <Input
              value={draft.address_line ?? ""}
              onChange={(e) =>
                setDraft((d) => ({ ...d, address_line: e.target.value }))
              }
            />
          </FormField>

          <FormField
            id="city"
            label={t("settings.public_page.city", "City")}
            error={field("city")}
          >
            <Input
              value={draft.city ?? ""}
              onChange={(e) =>
                setDraft((d) => ({ ...d, city: e.target.value }))
              }
            />
          </FormField>

          <FormField
            id="region"
            label={t("settings.public_page.region", "Region")}
            error={field("region")}
          >
            <Input
              value={draft.region ?? ""}
              onChange={(e) =>
                setDraft((d) => ({ ...d, region: e.target.value }))
              }
            />
          </FormField>

          <FormField
            id="website_url"
            label={t("settings.public_page.website", "Website")}
            hint={t(
              "settings.public_page.website_hint",
              "Must start with https://",
            )}
            error={field("website_url")}
          >
            <Input
              inputMode="url"
              placeholder="https://"
              value={draft.website_url ?? ""}
              onChange={(e) =>
                setDraft((d) => ({ ...d, website_url: e.target.value }))
              }
            />
          </FormField>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>
            {t("settings.public_page.social", "Social links")}
          </CardTitle>
        </CardHeader>
        <CardContent className="grid gap-4 sm:grid-cols-2">
          {SOCIAL_PLATFORMS.map((platform) => (
            <FormField
              key={platform}
              id={`social-${platform}`}
              label={t(`settings.public_page.social_${platform}`, platform)}
              error={errors[`social_links.${platform}`]?.[0]}
            >
              <Input
                inputMode="url"
                placeholder="https://"
                value={draft.social_links?.[platform] ?? ""}
                onChange={(e) =>
                  setDraft((d) => ({
                    ...d,
                    social_links: {
                      ...d.social_links,
                      [platform]: e.target.value,
                    },
                  }))
                }
              />
            </FormField>
          ))}
        </CardContent>
      </Card>

      <div className="flex justify-end">
        <Button
          disabled={save.isPending}
          onClick={() => {
            // `is_published` is deliberately excluded: the switch owns it and
            // saves on its own, so a content save can never publish by
            // accident.
            const { is_published: _ignored, ...changes } = draft;
            save.mutate(changes);
          }}
        >
          {save.isPending ? (
            <Loader2 className="mr-2 h-4 w-4 animate-spin" />
          ) : (
            <Save className="mr-2 h-4 w-4" />
          )}
          {t("common.save", "Save")}
        </Button>
      </div>
    </>
  );
}

function ImageUpload({
  label,
  endpoint,
  queryKey,
  t,
}: {
  label: string;
  endpoint: string;
  queryKey: string[];
  t: (key: string, fallback?: string) => string;
}) {
  const queryClient = useQueryClient();

  const upload = useMutation({
    mutationFn: async (file: File) => {
      const body = new FormData();
      body.append("image", file);
      const { data } = await apiClient.post(endpoint, body);
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey });
      toast.success(t("settings.public_page.image_saved", "Image updated"));
    },
    onError: () =>
      toast.error(
        t("settings.public_page.image_failed", "Couldn't upload that image"),
      ),
  });

  const inputId = `upload-${endpoint.replace(/\W+/g, "-")}`;

  return (
    <div className="flex items-center justify-between gap-4">
      <label className="text-sm font-medium" htmlFor={inputId}>
        {label}
      </label>
      <div className="flex items-center gap-2">
        <input
          id={inputId}
          className="sr-only"
          type="file"
          accept="image/png,image/jpeg,image/webp"
          onChange={(e) => {
            const file = e.target.files?.[0];
            if (file) upload.mutate(file);
            e.target.value = "";
          }}
        />
        <Button asChild variant="outline" size="sm" disabled={upload.isPending}>
          <label htmlFor={inputId} className="cursor-pointer">
            {upload.isPending ? (
              <Loader2 className="mr-2 h-4 w-4 animate-spin" />
            ) : (
              <Upload className="mr-2 h-4 w-4" />
            )}
            {t("settings.public_page.upload", "Upload")}
          </label>
        </Button>
      </div>
    </div>
  );
}
