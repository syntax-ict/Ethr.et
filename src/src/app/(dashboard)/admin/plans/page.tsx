"use client";

import { useId, useState } from "react";
import { Archive, Layers, Save } from "lucide-react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Switch } from "@/components/ui/switch";
import { Textarea } from "@/components/ui/textarea";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { RoleGate } from "@/components/shared/role-gate";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import {
  useAdminPlans,
  useRetireAdminPlan,
  useUpdateAdminPlan,
  type AdminPlan,
} from "@/features/admin/api";
import { useT } from "@/lib/i18n/useT";

/**
 * Super-admin editor for the plan catalog the public pricing page renders.
 *
 * Every figure here was a literal in `pricing-content.tsx` until this branch —
 * including a Professional price that disagreed with the database by 2.5x and
 * limits five times what `PlanLimitService` actually enforces. A wrong number
 * in a component is nobody's job to correct; the point of this screen is that
 * it becomes somebody's.
 *
 * The banner is not decoration either. Editing a price changes what new
 * customers are quoted and leaves existing subscribers on what they agreed to
 * — true only because `subscriptions.price_cents` freezes it, and worth saying
 * on the screen where someone is about to wonder.
 */
export default function AdminPlansPage() {
  const { t } = useT();
  const query = useAdminPlans();

  return (
    <RoleGate minRole="super_admin">
      <div className="space-y-6">
        <PageHeader
          title={t("admin_plans.title", "Plans")}
          description={t(
            "admin_plans.description",
            "The catalog the public pricing page reads",
          )}
        />

        <div
          role="note"
          className="rounded-xl border border-border/60 bg-muted/40 p-4 text-sm leading-relaxed text-muted-foreground"
        >
          {t(
            "admin_plans.pricing_note",
            "Changing a price sets what new customers are quoted. Organisations already subscribed keep the price they signed up at, and their invoices do not change.",
          )}
        </div>

        <QueryBoundary
          query={query}
          loading={<Skeleton className="h-96 w-full" />}
          isEmpty={(plans) => plans.length === 0}
          empty={
            <p className="text-sm text-muted-foreground">
              {t("admin_plans.empty", "No plans in the catalog yet.")}
            </p>
          }
        >
          {(plans) => (
            <div className="space-y-6">
              {plans.map((plan) => (
                <PlanCard key={plan.public_id} plan={plan} />
              ))}
            </div>
          )}
        </QueryBoundary>
      </div>
    </RoleGate>
  );
}

/** Cents in the database, major units in the form. */
function toMajor(cents: number): string {
  return (cents / 100).toFixed(2);
}

/**
 * Parses a price back to cents, or null when the field is not a usable number.
 *
 * Rounded rather than truncated: `Math.trunc(19.99 * 100)` is 1998 in binary
 * floating point, so a truncating parse quietly bills a cent less than the
 * operator typed, every month, for every subscriber on the plan.
 */
function toCents(major: string): number | null {
  const value = Number(major);
  if (!Number.isFinite(value) || value < 0) return null;
  return Math.round(value * 100);
}

/** A blank limit field means "no ceiling", which the API stores as null. */
function toLimit(raw: string): number | null {
  const trimmed = raw.trim();
  if (trimmed === "") return null;
  const value = Number(trimmed);
  return Number.isFinite(value) && value >= 1 ? Math.round(value) : null;
}

function toLines(list: string[] | null): string {
  return (list ?? []).join("\n");
}

function fromLines(text: string): string[] {
  return text
    .split("\n")
    .map((line) => line.trim())
    .filter((line) => line.length > 0);
}

function PlanCard({ plan }: { plan: AdminPlan }) {
  const { t } = useT();
  const update = useUpdateAdminPlan();
  const retire = useRetireAdminPlan();

  // useId for every label/control pair. The platform-settings screen documents
  // an a11y defect where only a placeholder gave an input its accessible name;
  // this is that lesson applied rather than rediscovered.
  const ids = {
    name: useId(),
    description: useId(),
    descriptionAm: useId(),
    price: useId(),
    employees: useId(),
    branches: useId(),
    devices: useId(),
    bullets: useId(),
    bulletsAm: useId(),
    isPublic: useId(),
    isPopular: useId(),
  };

  const [form, setForm] = useState({
    name: plan.name,
    description: plan.description ?? "",
    description_am: plan.description_am ?? "",
    price: toMajor(plan.price_cents),
    max_employees:
      plan.max_employees === null ? "" : String(plan.max_employees),
    max_branches: plan.max_branches === null ? "" : String(plan.max_branches),
    max_devices: plan.max_devices === null ? "" : String(plan.max_devices),
    marketing_features: toLines(plan.marketing_features),
    marketing_features_am: toLines(plan.marketing_features_am),
    is_public: plan.is_public,
    is_popular: plan.is_popular,
  });

  const set = <K extends keyof typeof form>(
    key: K,
    value: (typeof form)[K],
  ): void => setForm((previous) => ({ ...previous, [key]: value }));

  const onSave = () => {
    const priceCents = toCents(form.price);

    if (priceCents === null) {
      toast.error(
        t(
          "admin_plans.price_invalid",
          "Enter a price as a number, like 999.00",
        ),
      );
      return;
    }

    update.mutate(
      {
        publicId: plan.public_id,
        name: form.name,
        description: form.description || null,
        description_am: form.description_am || null,
        price_cents: priceCents,
        max_employees: toLimit(form.max_employees),
        max_branches: toLimit(form.max_branches),
        max_devices: toLimit(form.max_devices),
        marketing_features: fromLines(form.marketing_features),
        marketing_features_am: fromLines(form.marketing_features_am),
        is_public: form.is_public,
        is_popular: form.is_popular,
      },
      {
        onSuccess: () => toast.success(t("admin_plans.saved", "Plan updated")),
        onError: () =>
          toast.error(t("admin_plans.save_failed", "Could not save the plan")),
      },
    );
  };

  return (
    <Card>
      <CardHeader className="flex flex-row items-center justify-between gap-4">
        <CardTitle className="flex items-center gap-2">
          <Layers
            className="h-5 w-5 text-muted-foreground"
            aria-hidden="true"
          />
          {plan.name}
          <span className="text-sm font-normal text-muted-foreground">
            {plan.slug}
          </span>
        </CardTitle>
        {!plan.is_active && (
          <span className="rounded-full bg-muted px-3 py-1 text-xs font-medium text-muted-foreground">
            {t("admin_plans.retired", "Withdrawn from sale")}
          </span>
        )}
      </CardHeader>

      <CardContent className="space-y-5">
        <div className="grid gap-4 sm:grid-cols-2">
          <Field id={ids.name} label={t("admin_plans.name", "Name")}>
            <Input
              id={ids.name}
              value={form.name}
              onChange={(e) => set("name", e.target.value)}
            />
          </Field>

          <Field
            id={ids.price}
            label={t("admin_plans.price", "Price per month (:currency)", {
              currency: plan.currency,
            })}
          >
            <Input
              id={ids.price}
              inputMode="decimal"
              value={form.price}
              onChange={(e) => set("price", e.target.value)}
            />
          </Field>
        </div>

        <div className="grid gap-4 sm:grid-cols-2">
          <Field
            id={ids.description}
            label={t("admin_plans.summary", "One-line summary")}
          >
            <Input
              id={ids.description}
              value={form.description}
              onChange={(e) => set("description", e.target.value)}
            />
          </Field>
          <Field
            id={ids.descriptionAm}
            label={t("admin_plans.summary_am", "One-line summary (Amharic)")}
          >
            <Input
              id={ids.descriptionAm}
              value={form.description_am}
              onChange={(e) => set("description_am", e.target.value)}
            />
          </Field>
        </div>

        <div className="grid gap-4 sm:grid-cols-3">
          <Field
            id={ids.employees}
            label={t("admin_plans.max_employees", "Employee limit")}
            hint={t("admin_plans.blank_unlimited", "Leave blank for no limit")}
          >
            <Input
              id={ids.employees}
              inputMode="numeric"
              value={form.max_employees}
              onChange={(e) => set("max_employees", e.target.value)}
            />
          </Field>
          <Field
            id={ids.branches}
            label={t("admin_plans.max_branches", "Branch limit")}
            hint={t("admin_plans.blank_unlimited", "Leave blank for no limit")}
          >
            <Input
              id={ids.branches}
              inputMode="numeric"
              value={form.max_branches}
              onChange={(e) => set("max_branches", e.target.value)}
            />
          </Field>
          <Field
            id={ids.devices}
            label={t("admin_plans.max_devices", "Device limit")}
            hint={t("admin_plans.blank_unlimited", "Leave blank for no limit")}
          >
            <Input
              id={ids.devices}
              inputMode="numeric"
              value={form.max_devices}
              onChange={(e) => set("max_devices", e.target.value)}
            />
          </Field>
        </div>

        <div className="grid gap-4 sm:grid-cols-2">
          <Field
            id={ids.bullets}
            label={t("admin_plans.bullets", "Selling points")}
            hint={t("admin_plans.one_per_line", "One per line")}
          >
            <Textarea
              id={ids.bullets}
              rows={4}
              value={form.marketing_features}
              onChange={(e) => set("marketing_features", e.target.value)}
            />
          </Field>
          <Field
            id={ids.bulletsAm}
            label={t("admin_plans.bullets_am", "Selling points (Amharic)")}
            hint={t("admin_plans.one_per_line", "One per line")}
          >
            <Textarea
              id={ids.bulletsAm}
              rows={4}
              value={form.marketing_features_am}
              onChange={(e) => set("marketing_features_am", e.target.value)}
            />
          </Field>
        </div>

        <div className="flex flex-wrap items-center gap-6">
          <ToggleField
            id={ids.isPublic}
            label={t("admin_plans.is_public", "Show on the pricing page")}
            checked={form.is_public}
            onChange={(v) => set("is_public", v)}
          />
          <ToggleField
            id={ids.isPopular}
            label={t("admin_plans.is_popular", "Highlight as most popular")}
            checked={form.is_popular}
            onChange={(v) => set("is_popular", v)}
          />
        </div>

        <div className="flex flex-wrap gap-3 pt-2">
          <Button onClick={onSave} disabled={update.isPending}>
            <Save className="mr-2 h-4 w-4" aria-hidden="true" />
            {t("common.save", "Save")}
          </Button>

          {plan.is_active && (
            <Button
              variant="outline"
              disabled={retire.isPending}
              onClick={() => {
                retire.mutate(plan.public_id, {
                  onSuccess: () =>
                    toast.success(
                      t(
                        "admin_plans.retired_toast",
                        "Plan withdrawn from sale",
                      ),
                    ),
                });
              }}
            >
              <Archive className="mr-2 h-4 w-4" aria-hidden="true" />
              {t("admin_plans.retire", "Withdraw from sale")}
            </Button>
          )}
        </div>
      </CardContent>
    </Card>
  );
}

function Field({
  id,
  label,
  hint,
  children,
}: {
  id: string;
  label: string;
  hint?: string;
  children: React.ReactNode;
}) {
  return (
    <div className="space-y-1.5">
      <Label htmlFor={id}>{label}</Label>
      {children}
      {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
    </div>
  );
}

function ToggleField({
  id,
  label,
  checked,
  onChange,
}: {
  id: string;
  label: string;
  checked: boolean;
  onChange: (value: boolean) => void;
}) {
  return (
    <div className="flex items-center gap-2">
      <Switch id={id} checked={checked} onCheckedChange={onChange} />
      <Label htmlFor={id}>{label}</Label>
    </div>
  );
}
