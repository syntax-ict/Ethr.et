"use client";

import { useId, useState } from "react";
import { Globe, Landmark, Save } from "lucide-react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { RoleGate } from "@/components/shared/role-gate";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import {
  usePlatformSettings,
  useUpdatePlatformSettings,
  type PlatformSettings,
} from "@/features/admin/api";
import { useT } from "@/lib/i18n/useT";

/**
 * Super-admin editor for the bank account tenants pay ETHR into.
 *
 * These three fields are rendered verbatim on every tenant's billing page, so a
 * typo here silently sends customer payments somewhere else. That is why the
 * backend audits every change and validates the account number shape, and why
 * the form makes the blast radius explicit rather than looking like ordinary
 * settings.
 */
export default function PlatformSettingsPage() {
  const { t } = useT();
  const query = usePlatformSettings();

  return (
    <RoleGate minRole="super_admin">
      <div className="space-y-6">
        <PageHeader
          title={t("platform_settings.title", "Platform Settings")}
          description={t(
            "platform_settings.description",
            "Payment details shown to every tenant on their billing page",
          )}
        />

        <QueryBoundary
          query={query}
          loading={<Skeleton className="h-96 w-full" />}
          isEmpty={() => false}
        >
          {(settings) => (
            <div className="space-y-6">
              <PaymentForm settings={settings} />
              <SiteContentForm settings={settings} />
            </div>
          )}
        </QueryBoundary>
      </div>
    </RoleGate>
  );
}

function PaymentForm({ settings }: { settings: PlatformSettings }) {
  const { t } = useT();
  const update = useUpdatePlatformSettings();

  const seed = (s: PlatformSettings) => ({
    bank_name: s.bank_name ?? "",
    bank_account_number: s.bank_account_number ?? "",
    bank_account_name: s.bank_account_name ?? "",
    payment_instructions: s.payment_instructions ?? "",
    payment_instructions_am: s.payment_instructions_am ?? "",
  });

  const [form, setForm] = useState(() => seed(settings));

  // Re-seed when the query refetches, so a save elsewhere is not overwritten by
  // stale local state. Adjusting state during render (rather than in an effect)
  // avoids an extra commit — see https://react.dev/learn/you-might-not-need-an-effect.
  const [prevSettings, setPrevSettings] = useState(settings);
  if (settings !== prevSettings) {
    setPrevSettings(settings);
    setForm(seed(settings));
  }

  const set = (key: keyof typeof form) => (value: string) =>
    setForm((prev) => ({ ...prev, [key]: value }));

  // Both textareas rendered a <Label> that was never associated with them (no
  // htmlFor/id pair), so screen readers announced two unlabelled text boxes.
  // The Input fields above escaped the same bug only because a placeholder
  // acts as a last-resort accessible name — see the Field helper below.
  const instructionsEnId = useId();
  const instructionsAmId = useId();

  function save() {
    update.mutate(form, {
      onSuccess: () =>
        toast.success(t("platform_settings.saved", "Payment details updated")),
      onError: () =>
        toast.error(
          t("platform_settings.save_failed", "Could not save changes"),
        ),
    });
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-base">
          <Landmark className="h-4 w-4 text-status-info" />
          {t("platform_settings.bank_account", "Bank Account")}
        </CardTitle>
        <p className="text-sm text-muted-foreground">
          {t(
            "platform_settings.bank_account_desc",
            "Shown to every tenant as the destination for subscription payments. Changes are recorded in the audit log.",
          )}
        </p>
      </CardHeader>
      <CardContent className="space-y-4">
        <div className="grid gap-4 sm:grid-cols-3">
          <Field
            label={t("billing.bank", "Bank")}
            value={form.bank_name}
            onChange={set("bank_name")}
            placeholder="Commercial Bank of Ethiopia"
          />
          <Field
            label={t("billing.account_number", "Account Number")}
            value={form.bank_account_number}
            onChange={set("bank_account_number")}
            placeholder="1000 0000 0000 00"
            mono
          />
          <Field
            label={t("billing.account_name", "Account Name")}
            value={form.bank_account_name}
            onChange={set("bank_account_name")}
            placeholder="ETHR Technologies PLC"
          />
        </div>

        <div className="grid gap-4 sm:grid-cols-2">
          <div className="space-y-1.5">
            <Label className="text-xs" htmlFor={instructionsEnId}>
              {t("platform_settings.instructions_en", "Instructions (English)")}
            </Label>
            <Textarea
              id={instructionsEnId}
              rows={3}
              value={form.payment_instructions}
              onChange={(e) => set("payment_instructions")(e.target.value)}
            />
          </div>
          <div className="space-y-1.5">
            <Label className="text-xs" htmlFor={instructionsAmId}>
              {t("platform_settings.instructions_am", "Instructions (Amharic)")}
            </Label>
            <Textarea
              id={instructionsAmId}
              rows={3}
              value={form.payment_instructions_am}
              onChange={(e) => set("payment_instructions_am")(e.target.value)}
            />
          </div>
        </div>

        <div className="flex items-center justify-between border-t pt-4">
          <p className="text-xs text-muted-foreground">
            {settings.is_configured
              ? t(
                  "platform_settings.visible_to_tenants",
                  "Visible to all tenants",
                )
              : t(
                  "platform_settings.incomplete",
                  "Tenants see no payment details until all three fields are filled.",
                )}
          </p>
          <Button onClick={save} disabled={update.isPending}>
            <Save className="mr-2 h-4 w-4" />
            {t("common.save_changes", "Save Changes")}
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}

/**
 * The facts the public marketing site states about ETHR.
 *
 * Same row, same permission and same audit entry as the bank details above —
 * these are columns on `platform_settings` rather than a new table precisely so
 * they inherit all of it.
 *
 * The metric fields are the reason this card exists in the shape it does. The
 * landing page claims "500+ organisations" and "99.9% uptime" and nothing can
 * substantiate either; leaving these blank now renders no badge at all, which
 * is the honest state rather than a missing feature.
 */
function SiteContentForm({ settings }: { settings: PlatformSettings }) {
  const { t } = useT();
  const update = useUpdatePlatformSettings();

  const seed = (s: PlatformSettings) => ({
    platform_name: s.platform_name ?? "",
    platform_name_am: s.platform_name_am ?? "",
    tagline: s.tagline ?? "",
    tagline_am: s.tagline_am ?? "",
    logo_url: s.logo_url ?? "",
    contact_email: s.contact_email ?? "",
    contact_phone: s.contact_phone ?? "",
    office_address: s.office_address ?? "",
    office_address_am: s.office_address_am ?? "",
    social_linkedin: s.social_linkedin ?? "",
    social_x: s.social_x ?? "",
    social_facebook: s.social_facebook ?? "",
    metric_organisations:
      s.metric_organisations === null ? "" : String(s.metric_organisations),
    metric_employees:
      s.metric_employees === null ? "" : String(s.metric_employees),
    metric_uptime_note: s.metric_uptime_note ?? "",
    metric_uptime_note_am: s.metric_uptime_note_am ?? "",
    testimonial_quote: s.testimonial_quote ?? "",
    testimonial_quote_am: s.testimonial_quote_am ?? "",
    testimonial_author: s.testimonial_author ?? "",
    testimonial_role: s.testimonial_role ?? "",
    testimonial_role_am: s.testimonial_role_am ?? "",
    testimonial_organisation: s.testimonial_organisation ?? "",
    testimonial_consented_on: s.testimonial_consented_on ?? "",
  });

  const [form, setForm] = useState(() => seed(settings));

  // Re-seed on refetch, as the payment form does and for the same reason.
  const [prevSettings, setPrevSettings] = useState(settings);
  if (settings !== prevSettings) {
    setPrevSettings(settings);
    setForm(seed(settings));
  }

  const set = (key: keyof typeof form) => (value: string) =>
    setForm((prev) => ({ ...prev, [key]: value }));

  /**
   * Blank means "not published", which the API stores as null.
   *
   * Sending "" would fail integer validation, and sending 0 would publish the
   * claim that ETHR has zero customers — a different statement from making no
   * claim at all, and the whole reason these columns are nullable.
   */
  const metric = (raw: string): number | null => {
    const trimmed = raw.trim();
    if (trimmed === "") return null;
    const value = Number(trimmed);
    return Number.isFinite(value) && value >= 0 ? Math.round(value) : null;
  };

  function save() {
    update.mutate(
      {
        ...form,
        // Empty strings become null so a cleared field is cleared rather than
        // stored as "".
        platform_name: form.platform_name || null,
        platform_name_am: form.platform_name_am || null,
        tagline: form.tagline || null,
        tagline_am: form.tagline_am || null,
        logo_url: form.logo_url || null,
        contact_email: form.contact_email || null,
        contact_phone: form.contact_phone || null,
        office_address: form.office_address || null,
        office_address_am: form.office_address_am || null,
        social_linkedin: form.social_linkedin || null,
        social_x: form.social_x || null,
        social_facebook: form.social_facebook || null,
        metric_organisations: metric(form.metric_organisations),
        metric_employees: metric(form.metric_employees),
        metric_uptime_note: form.metric_uptime_note || null,
        metric_uptime_note_am: form.metric_uptime_note_am || null,
        testimonial_quote: form.testimonial_quote || null,
        testimonial_quote_am: form.testimonial_quote_am || null,
        testimonial_author: form.testimonial_author || null,
        testimonial_role: form.testimonial_role || null,
        testimonial_role_am: form.testimonial_role_am || null,
        testimonial_organisation: form.testimonial_organisation || null,
        testimonial_consented_on: form.testimonial_consented_on || null,
      },
      {
        onSuccess: () =>
          toast.success(t("site_content.saved", "Public site content updated")),
        onError: () =>
          toast.error(
            t("site_content.save_failed", "Could not save the site content"),
          ),
      },
    );
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-base">
          <Globe className="h-4 w-4 text-status-info" />
          {t("site_content.title", "Public Site")}
        </CardTitle>
        <p className="text-sm text-muted-foreground">
          {t(
            "site_content.description",
            "Contact details, brand and figures shown on the public marketing pages. Changes are recorded in the audit log.",
          )}
        </p>
      </CardHeader>
      <CardContent className="space-y-4">
        <div className="grid gap-4 sm:grid-cols-2">
          <Field
            label={t("site_content.platform_name", "Name")}
            value={form.platform_name}
            onChange={set("platform_name")}
            placeholder="ETHR"
          />
          <Field
            label={t("site_content.platform_name_am", "Name (Amharic)")}
            value={form.platform_name_am}
            onChange={set("platform_name_am")}
          />
          <Field
            label={t("site_content.tagline", "Tagline")}
            value={form.tagline}
            onChange={set("tagline")}
          />
          <Field
            label={t("site_content.tagline_am", "Tagline (Amharic)")}
            value={form.tagline_am}
            onChange={set("tagline_am")}
          />
        </div>

        <Field
          label={t("site_content.logo_url", "Logo URL (https)")}
          value={form.logo_url}
          onChange={set("logo_url")}
          placeholder="https://example.com/logo.png"
        />

        <div className="grid gap-4 sm:grid-cols-2">
          <Field
            label={t("site_content.contact_email", "Contact email")}
            value={form.contact_email}
            onChange={set("contact_email")}
          />
          <Field
            label={t("site_content.contact_phone", "Contact phone")}
            value={form.contact_phone}
            onChange={set("contact_phone")}
          />
          <Field
            label={t("site_content.office_address", "Office address")}
            value={form.office_address}
            onChange={set("office_address")}
          />
          <Field
            label={t(
              "site_content.office_address_am",
              "Office address (Amharic)",
            )}
            value={form.office_address_am}
            onChange={set("office_address_am")}
          />
        </div>

        <div className="grid gap-4 sm:grid-cols-3">
          <Field
            label={t("site_content.social_linkedin", "LinkedIn URL")}
            value={form.social_linkedin}
            onChange={set("social_linkedin")}
          />
          <Field
            label={t("site_content.social_x", "X URL")}
            value={form.social_x}
            onChange={set("social_x")}
          />
          <Field
            label={t("site_content.social_facebook", "Facebook URL")}
            value={form.social_facebook}
            onChange={set("social_facebook")}
          />
        </div>

        <div className="rounded-lg border border-border/60 bg-muted/30 p-4">
          <p className="text-xs text-muted-foreground">
            {t(
              "site_content.metrics_note",
              "Leave a figure blank and the site shows nothing in its place. Publish only what you can stand behind.",
            )}
          </p>
          <div className="mt-3 grid gap-4 sm:grid-cols-2">
            <Field
              label={t("site_content.metric_organisations", "Organisations")}
              value={form.metric_organisations}
              onChange={set("metric_organisations")}
            />
            <Field
              label={t("site_content.metric_employees", "Employees")}
              value={form.metric_employees}
              onChange={set("metric_employees")}
            />
          </div>
        </div>

        <div className="rounded-lg border border-border/60 bg-muted/30 p-4">
          <p className="text-xs text-muted-foreground">
            {t(
              "site_content.testimonial_note",
              "A quote from a real customer who has agreed to be quoted. The author and the date they agreed are both required — without them the quote is not published, and the landing page shows nothing in its place.",
            )}
          </p>
          <div className="mt-3 space-y-4">
            <Field
              label={t("site_content.testimonial_quote", "Quote")}
              value={form.testimonial_quote}
              onChange={set("testimonial_quote")}
            />
            <Field
              label={t("site_content.testimonial_quote_am", "Quote (Amharic)")}
              value={form.testimonial_quote_am}
              onChange={set("testimonial_quote_am")}
            />
            <div className="grid gap-4 sm:grid-cols-2">
              <Field
                label={t("site_content.testimonial_author", "Author")}
                value={form.testimonial_author}
                onChange={set("testimonial_author")}
              />
              <Field
                label={t(
                  "site_content.testimonial_organisation",
                  "Organisation",
                )}
                value={form.testimonial_organisation}
                onChange={set("testimonial_organisation")}
              />
              <Field
                label={t("site_content.testimonial_role", "Role")}
                value={form.testimonial_role}
                onChange={set("testimonial_role")}
              />
              <Field
                label={t("site_content.testimonial_role_am", "Role (Amharic)")}
                value={form.testimonial_role_am}
                onChange={set("testimonial_role_am")}
              />
            </div>
            <Field
              type="date"
              label={t(
                "site_content.testimonial_consented_on",
                "Date they agreed to be quoted",
              )}
              value={form.testimonial_consented_on}
              onChange={set("testimonial_consented_on")}
            />
          </div>
        </div>

        <div className="flex items-center justify-between border-t pt-4">
          <p className="text-xs text-muted-foreground">
            {settings.has_published_metrics
              ? t("site_content.metrics_live", "Figures are live on the site.")
              : t(
                  "site_content.metrics_empty",
                  "No figures published — the site states none.",
                )}
          </p>
          <Button onClick={save} disabled={update.isPending}>
            <Save className="mr-2 h-4 w-4" />
            {t("common.save_changes", "Save Changes")}
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}

function Field({
  label,
  value,
  onChange,
  placeholder,
  mono,
  type,
}: {
  label: string;
  value: string;
  onChange: (v: string) => void;
  placeholder?: string;
  mono?: boolean;
  type?: string;
}) {
  // The label was previously unassociated here too. axe did not flag it because
  // every call site passes a placeholder, which the accessible-name computation
  // falls back to — so the control had a name by accident, and would have lost
  // it the moment someone dropped a placeholder. Wire it properly instead.
  const id = useId();

  return (
    <div className="space-y-1.5">
      <Label className="text-xs" htmlFor={id}>
        {label}
      </Label>
      <Input
        id={id}
        type={type}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        placeholder={placeholder}
        className={mono ? "font-mono" : undefined}
      />
    </div>
  );
}
