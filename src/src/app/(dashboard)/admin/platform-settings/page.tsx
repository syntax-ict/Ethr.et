"use client";

import { useId, useState } from "react";
import { Landmark, Save } from "lucide-react";
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
          {(settings) => <PaymentForm settings={settings} />}
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

function Field({
  label,
  value,
  onChange,
  placeholder,
  mono,
}: {
  label: string;
  value: string;
  onChange: (v: string) => void;
  placeholder?: string;
  mono?: boolean;
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
        value={value}
        onChange={(e) => onChange(e.target.value)}
        placeholder={placeholder}
        className={mono ? "font-mono" : undefined}
      />
    </div>
  );
}
