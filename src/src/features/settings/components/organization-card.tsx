"use client";

import { TenantAddressAffix } from "@/components/shared/tenant-address-affix";
import { useState } from "react";
import { Building2, Loader2, Save } from "lucide-react";
import { toast } from "sonner";
import {
  useUpdateOrganization,
  type OrganizationUpdate,
} from "@/features/settings/api";
import { useT } from "@/lib/i18n/useT";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";

/**
 * Organization profile — name, type, timezone, default language.
 *
 * `PUT /settings/organization` existed with no consumer, so the whole card used
 * to be read-only ("contact support to change"). Subdomain stays read-only on
 * purpose: it is the tenant's routing identity, and changing it would break
 * every saved link and SSO ACS URL.
 *
 * Language offers only the locales `supportedLocales` marks `available` — the
 * others are registered stub files with no translations yet, and defaulting new
 * users into one would give them an untranslated UI.
 */

const ORG_TYPES = [
  "private",
  "government",
  "university",
  "ngo",
  "bank",
  "hospital",
  "manufacturing",
  "hotel",
] as const;

type OrgType = (typeof ORG_TYPES)[number];

const TIMEZONES = [
  "Africa/Addis_Ababa",
  "Africa/Nairobi",
  "Africa/Djibouti",
  "Africa/Mogadishu",
  "Africa/Asmara",
  "UTC",
] as const;

export interface OrganizationCardProps {
  organization?: {
    name?: string | null;
    subdomain?: string | null;
    type?: string | null;
    timezone?: string | null;
    locale?: string | null;
  } | null;
}

export function OrganizationCard({ organization }: OrganizationCardProps) {
  const { t } = useT();
  const updateOrganization = useUpdateOrganization();

  const [name, setName] = useState(organization?.name ?? "");
  const [type, setType] = useState(organization?.type ?? "private");
  const [timezone, setTimezone] = useState(
    organization?.timezone ?? "Africa/Addis_Ababa",
  );
  const [locale, setLocale] = useState(organization?.locale ?? "en");

  const dirty =
    name !== (organization?.name ?? "") ||
    type !== (organization?.type ?? "private") ||
    timezone !== (organization?.timezone ?? "Africa/Addis_Ababa") ||
    locale !== (organization?.locale ?? "en");

  // The hook refreshes /auth/me as well as the settings query: the tenant name
  // is rendered from it in the sidebar and header.
  function save() {
    updateOrganization.mutate(
      {
        name,
        type,
        timezone,
        locale: locale as OrganizationUpdate["locale"],
      },
      {
        onSuccess: () =>
          toast.success(
            t("settings.organization_saved", "Organization updated"),
          ),
        onError: () =>
          toast.error(
            t(
              "settings.organization_save_failed",
              "Failed to update organization",
            ),
          ),
      },
    );
  }

  return (
    <Card>
      <CardHeader>
        <div className="flex items-center gap-2">
          <Building2 className="h-4 w-4 text-muted-foreground" />
          <CardTitle className="text-base">
            {t("settings.organization", "Organization")}
          </CardTitle>
        </div>
      </CardHeader>
      <CardContent className="space-y-4">
        <div className="grid gap-4 sm:grid-cols-2">
          <div>
            <Label htmlFor="org-name">
              {t("settings.organization_name", "Organization Name")}
            </Label>
            <Input
              id="org-name"
              value={name}
              onChange={(e) => setName(e.target.value)}
              className="mt-1"
            />
          </div>
          <div>
            <Label htmlFor="org-subdomain">
              {t("settings.subdomain", "Subdomain")}
            </Label>
            <div className="mt-1 flex items-center gap-2">
              <TenantAddressAffix side="prefix" />
              <Input
                id="org-subdomain"
                value={organization?.subdomain ?? ""}
                disabled
              />
              <TenantAddressAffix side="suffix" />
            </div>
            <p className="mt-1 text-xs text-muted-foreground">
              {t("settings.contact_support", "Contact support to change")}
            </p>
          </div>
          <div>
            <Label htmlFor="org-type">
              {t("settings.organization_type", "Organization Type")}
            </Label>
            <Select value={type} onValueChange={setType}>
              <SelectTrigger id="org-type" className="mt-1">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {ORG_TYPES.map((value) => (
                  <SelectItem key={value} value={value}>
                    {orgTypeLabel(value, t)}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <div>
            <Label htmlFor="org-timezone">
              {t("settings.timezone", "Timezone")}
            </Label>
            <Select value={timezone} onValueChange={setTimezone}>
              <SelectTrigger id="org-timezone" className="mt-1">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {TIMEZONES.map((tz) => (
                  <SelectItem key={tz} value={tz}>
                    {tz}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <div>
            <Label htmlFor="org-language">
              {t("settings.default_language", "Default Language")}
            </Label>
            <Select value={locale} onValueChange={setLocale}>
              <SelectTrigger id="org-language" className="mt-1">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="en">
                  {t("settings.english", "English")}
                </SelectItem>
                <SelectItem value="am">
                  {t("settings.amharic", "Amharic")}
                </SelectItem>
              </SelectContent>
            </Select>
            <p className="mt-1 text-xs text-muted-foreground">
              {t(
                "settings.default_language_help",
                "Applied to newly invited users. Each user can still choose their own.",
              )}
            </p>
          </div>
        </div>

        <div className="flex justify-end">
          <Button
            onClick={save}
            disabled={!dirty || updateOrganization.isPending}
          >
            {updateOrganization.isPending ? (
              <Loader2 className="mr-2 h-4 w-4 animate-spin" />
            ) : (
              <Save className="mr-2 h-4 w-4" />
            )}
            {t("settings.save_organization", "Save organization")}
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}

/**
 * Literal `t()` calls, not a `settings.org_type_${value}` template: the i18n
 * gate only sees string-literal keys, so a dynamic one would never be checked
 * for en/am coverage.
 */
function orgTypeLabel(value: OrgType, t: (k: string, f: string) => string) {
  switch (value) {
    case "private":
      return t("settings.org_type_private", "Private company");
    case "government":
      return t("settings.org_type_government", "Government");
    case "university":
      return t("settings.org_type_university", "University / TVET");
    case "ngo":
      return t("settings.org_type_ngo", "NGO");
    case "bank":
      return t("settings.org_type_bank", "Bank / Finance");
    case "hospital":
      return t("settings.org_type_hospital", "Hospital / Health");
    case "manufacturing":
      return t("settings.org_type_manufacturing", "Manufacturing");
    case "hotel":
      return t("settings.org_type_hotel", "Hotel / Hospitality");
  }
}
