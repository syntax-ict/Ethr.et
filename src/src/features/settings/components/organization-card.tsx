"use client";

import { TenantAddressAffix } from "@/components/shared/tenant-address-affix";
import { useState } from "react";
import {
  Building2,
  CalendarClock,
  Landmark,
  Loader2,
  Save,
} from "lucide-react";
import { toast } from "sonner";
import {
  useOrganizationTypes,
  useUpdateOrganization,
  type OrganizationUpdate,
} from "@/features/settings/api";
import { useT } from "@/lib/i18n/useT";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { ErrorState } from "@/components/shared/error-state";
import { DualCalendarDateInput } from "@/components/shared/dual-calendar-date-input";
import { useCalendar } from "@/lib/calendar/calendar-context";
import { dateFromIso } from "@/lib/calendar/date-from-iso";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { apiErrorMessage } from "@/lib/api/error-message";
import { orgTypeLabel } from "@/features/settings/org-type-label";
import { OrgKindSelect } from "@/features/settings/components/org-kind-select";
import { orgKindName } from "@/features/settings/org-kind";

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

// `general` is what registration defaults to and what applying a services,
// trade or custom template records; without it those organisations saw an
// empty Type field.
const ORG_TYPES = [
  "general",
  "private",
  "government",
  "university",
  "ngo",
  "bank",
  "hospital",
  "manufacturing",
  "hotel",
] as const;

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
    organization_type?: string | null;
    organization_type_needs_review?: boolean | null;
    organization_type_pending?: {
      organization_type: string;
      effective_from: string;
    } | null;
    organization_type_earliest_change?: string | null;
  } | null;
}

export function OrganizationCard({ organization }: OrganizationCardProps) {
  const { t, locale: uiLocale } = useT();
  const { formatDate } = useCalendar();
  const updateOrganization = useUpdateOrganization();
  const organizationTypes = useOrganizationTypes();

  const [name, setName] = useState(organization?.name ?? "");
  const [type, setType] = useState(organization?.type ?? "private");
  const [timezone, setTimezone] = useState(
    organization?.timezone ?? "Africa/Addis_Ababa",
  );
  const [locale, setLocale] = useState(organization?.locale ?? "en");
  // The kind of organisation in law, which decides the payroll rules applied
  // (pension scheme, fiscal year). Distinct from `type`, the industry.
  const [kind, setKind] = useState(
    organization?.organization_type ?? "private",
  );
  const needsReview = organization?.organization_type_needs_review === true;
  // Once payroll is approved a change of kind is never retroactive: it
  // starts on a later month's first day, which the server names.
  const earliestChange =
    organization?.organization_type_earliest_change ?? null;
  const pending = organization?.organization_type_pending ?? null;
  const [kindFrom, setKindFrom] = useState(earliestChange ?? "");
  const askDate =
    earliestChange !== null &&
    kind !== (organization?.organization_type ?? "private");
  const kindNameOf = (code: string) => {
    const found = organizationTypes.data?.find((k) => k.code === code);
    return found ? orgKindName(found, uiLocale) : code;
  };

  const dirty =
    name !== (organization?.name ?? "") ||
    type !== (organization?.type ?? "private") ||
    timezone !== (organization?.timezone ?? "Africa/Addis_Ababa") ||
    locale !== (organization?.locale ?? "en") ||
    kind !== (organization?.organization_type ?? "private");

  // The hook refreshes /auth/me as well as the settings query: the tenant name
  // is rendered from it in the sidebar and header. Sending the kind, even
  // unchanged, is what confirms it after the migration guessed it.
  function save() {
    updateOrganization.mutate(
      {
        name,
        type,
        timezone,
        locale: locale as OrganizationUpdate["locale"],
        organization_type: kind,
        ...(askDate ? { effective_from: kindFrom } : {}),
      },
      {
        onSuccess: () =>
          toast.success(
            t("settings.organization_saved", "Organisation updated"),
          ),
        onError: (err) =>
          toast.error(
            apiErrorMessage(
              err,
              t(
                "settings.organization_save_failed",
                "Could not update the organisation",
              ),
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
            {t("settings.organization", "Organisation")}
          </CardTitle>
        </div>
      </CardHeader>
      <CardContent className="space-y-4">
        {needsReview && (
          <div
            role="status"
            className="flex flex-col gap-3 rounded-md border border-warning-edge bg-warning-soft p-3 sm:flex-row sm:items-center sm:justify-between"
          >
            <div className="flex items-start gap-2">
              <Landmark
                className="mt-0.5 h-4 w-4 shrink-0 text-warning-on-soft"
                aria-hidden="true"
              />
              <p className="text-sm text-warning-on-soft">
                {t(
                  "settings.organization_kind_review",
                  "Confirm the kind of organisation below. Payroll applies the pension scheme and fiscal year set for it in law.",
                )}
              </p>
            </div>
            <Button
              size="sm"
              variant="outline"
              onClick={save}
              disabled={updateOrganization.isPending}
            >
              {t("settings.organization_kind_confirm", "Confirm")}
            </Button>
          </div>
        )}
        <div className="grid gap-4 sm:grid-cols-2">
          <div>
            <Label htmlFor="org-name">
              {t("settings.organization_name", "Organisation name")}
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
              {t("settings.subdomain", "Address name")}
            </Label>
            <div className="mt-1 flex items-center gap-2">
              <TenantAddressAffix side="prefix" />
              <Input
                id="org-subdomain"
                value={organization?.subdomain ?? ""}
                readOnly
              />
              <TenantAddressAffix side="suffix" />
            </div>
            <p className="mt-1 text-xs text-muted-foreground">
              {t(
                "settings.subdomain_change_hint",
                "Change it under Address, below.",
              )}
            </p>
          </div>
          <div>
            <Label htmlFor="org-kind">
              {t("settings.organization_kind", "Kind of organisation in law")}
            </Label>
            {organizationTypes.isError ? (
              <div className="mt-1">
                <ErrorState
                  compact
                  onRetry={() => organizationTypes.refetch()}
                />
              </div>
            ) : (
              <div
                className="mt-1"
                aria-busy={organizationTypes.isPending || undefined}
              >
                <OrgKindSelect
                  id="org-kind"
                  kinds={organizationTypes.data ?? []}
                  value={kind}
                  onChange={setKind}
                  disabled={organizationTypes.isPending}
                />
              </div>
            )}
            <p className="mt-1 text-xs text-muted-foreground">
              {t(
                "settings.organization_kind_help",
                "Decides the statutory payroll rules: the pension scheme and the fiscal year.",
              )}
            </p>
            {askDate && earliestChange && (
              <div className="mt-3">
                <Label htmlFor="org-kind-from">
                  {t("settings.organization_kind_from", "Applies from")}
                </Label>
                <DualCalendarDateInput
                  id="org-kind-from"
                  value={kindFrom}
                  onChange={setKindFrom}
                  required
                  className="mt-1"
                />
                <p className="mt-1 text-xs text-muted-foreground">
                  {t(
                    "settings.organization_kind_from_help",
                    "Payroll has been approved under the current kind, so the change starts on the first day of a later month: :date or after.",
                    { date: formatDate(dateFromIso(earliestChange)) },
                  )}
                </p>
              </div>
            )}
            {pending && (
              <p
                role="status"
                className="mt-2 flex items-start gap-1.5 text-xs text-muted-foreground"
              >
                <CalendarClock
                  className="mt-0.5 h-3.5 w-3.5 shrink-0"
                  aria-hidden="true"
                />
                {t(
                  "settings.organization_kind_pending",
                  "Becomes :kind on :date. Payroll before then keeps the current kind.",
                  {
                    kind: kindNameOf(pending.organization_type),
                    date: formatDate(dateFromIso(pending.effective_from)),
                  },
                )}
              </p>
            )}
          </div>
          <div>
            <Label htmlFor="org-type">
              {t("settings.industry", "Industry")}
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
              {t("settings.default_language", "Default language")}
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
                <SelectItem value="om">
                  {t("settings.oromo", "Afaan Oromoo")}
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
            {t("settings.save_organization", "Save organisation")}
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}
