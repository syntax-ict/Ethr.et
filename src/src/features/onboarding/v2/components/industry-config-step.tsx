"use client";

import { useState } from "react";
import { Loader2, X, Check, ShieldAlert } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Checkbox } from "@/components/ui/checkbox";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import { cn } from "@/lib/utils";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";
import {
  useApplyConfiguration,
  useConfigurationProfiles,
  useIndustries,
  usePreviewConfiguration,
} from "../api";
import type { ConfigurationPlan, ConfigurationProfile } from "../types";
import { SourceLabel } from "./source-label";
import { useCurrentTenant } from "@/features/auth/api";

const SECTION_TITLES: Record<string, [string, string]> = {
  branches: ["setup2.section_branches", "Branches"],
  departments: ["setup2.section_departments", "Departments"],
  positions: ["setup2.section_positions", "Positions"],
  grades: ["setup2.section_grades", "Grades"],
  shifts: ["setup2.section_shifts", "Shifts"],
  rotations: ["setup2.section_rotations", "Shift rotations"],
  leave_types: ["setup2.section_leave_types", "Leave types"],
  allowances: ["setup2.section_allowances", "Allowances"],
};

const LIST_SECTIONS = [
  "branches",
  "departments",
  "positions",
  "grades",
  "shifts",
  "rotations",
  "leave_types",
  "allowances",
] as const;

export function IndustryConfigStep({ onApplied }: { onApplied?: () => void }) {
  const { t, locale } = useT();
  const industriesQuery = useIndustries();
  const profilesQuery = useConfigurationProfiles();
  const preview = usePreviewConfiguration();
  const apply = useApplyConfiguration();

  const { data: tenant } = useCurrentTenant();
  const [chosen, setChosen] = useState<string | null>(null);
  const [employeeCount, setEmployeeCount] = useState("");
  const [region, setRegion] = useState("");
  // null = the industry's usual profile; set only when the admin changes it,
  // so the preview can tell an explicit choice from a default.
  const [workforceProfile, setWorkforceProfile] = useState<string | null>(null);
  const [policyProfile, setPolicyProfile] = useState<string | null>(null);
  const [plan, setPlan] = useState<ConfigurationPlan | null>(null);
  const [reviewed, setReviewed] = useState(false);
  const [showAllTypes, setShowAllTypes] = useState(false);

  // Sign-up already asked what kind of organisation this is, so only the
  // industries of that kind are offered: Government shows the ministries,
  // bureaus and administrations, Bank shows banks, insurers and microfinance.
  // A sign-up type is an industry key (bank, hospital) or the base several
  // industries share (government). "General" names no kind and keeps them all.
  const signupType = tenant?.type ?? null;
  const allIndustries = industriesQuery.data ?? [];
  const ofSignupType = allIndustries.filter(
    (ind) => ind.key === signupType || ind.base === signupType,
  );
  const narrowed = !showAllTypes && ofSignupType.length > 0;
  const shown = narrowed ? ofSignupType : allIndustries;

  const grouped: Record<string, typeof shown> = {};
  for (const ind of shown) {
    (grouped[ind.group] ??= []).push(ind);
  }

  // The sign-up answer starts selected where it names one industry: the
  // industry with that key, or the only one of that kind (onboarding review
  // 2026-10-30), so the question is not asked twice.
  const suggested =
    ofSignupType.find((ind) => ind.key === signupType)?.key ??
    (ofSignupType.length === 1 ? ofSignupType[0].key : null);
  const selected = chosen ?? suggested;

  const selectedIndustry = (industriesQuery.data ?? []).find(
    (ind) => ind.key === selected,
  );
  const workforceOptions = profilesQuery.data?.workforce ?? [];
  const policyOptions = profilesQuery.data?.policy ?? [];
  const label = (p: { label: string; label_am: string }) =>
    locale === "am" ? p.label_am : p.label;

  function chooseIndustry(key: string) {
    setChosen(key);
    setWorkforceProfile(null);
    setPolicyProfile(null);
  }

  function itemLabel(section: string, item: unknown): string {
    if (typeof item === "string") return item;
    if (item && typeof item === "object") {
      const o = item as Record<string, unknown>;
      const localName =
        locale === "am" ? (o.name_am ?? o.title_am ?? null) : null;
      const name = String(localName ?? o.name ?? o.title ?? o.code ?? "");
      if (section === "shifts" && o.start_time && o.end_time) {
        return `${name} ${String(o.start_time)}–${String(o.end_time)}`;
      }
      if (section === "rotations" && typeof o.cycle_days === "number") {
        return `${name} · ${t("setup2.cycle_days", ":n-day cycle", { n: o.cycle_days })}`;
      }
      return name || JSON.stringify(o);
    }
    return String(item);
  }

  // Literal keys, so the i18n gate checks each one for en/am coverage.
  function attendanceName(key: string): string {
    switch (key) {
      case "grace_period_minutes":
        return t(
          "setup2.attendance_grace_period_minutes",
          "Grace period (minutes)",
        );
      case "auto_checkout_enabled":
        return t(
          "setup2.attendance_auto_checkout_enabled",
          "Automatic check-out",
        );
      case "mobile_photo_required":
        return t(
          "setup2.attendance_mobile_photo_required",
          "Photo with phone check-in",
        );
      default:
        return key.replace(/_/g, " ");
    }
  }

  function attendanceLabel(key: string, value: unknown): string {
    const name = attendanceName(key);
    if (typeof value === "boolean") {
      return `${name}: ${value ? t("common.on", "On") : t("common.off", "Off")}`;
    }
    return `${name}: ${String(value)}`;
  }

  async function runPreview() {
    if (!selected) return;
    try {
      const result = await preview.mutateAsync({
        industry: selected,
        employee_count: employeeCount ? Number(employeeCount) : undefined,
        region: region || undefined,
        workforce_profile: workforceProfile ?? undefined,
        policy_profile: policyProfile ?? undefined,
      });
      setReviewed(false);
      setPlan(result);
    } catch {
      toast.error(
        t("setup2.preview_failed", "Could not generate configuration"),
      );
    }
  }

  function removeItem(section: string, index: number) {
    setPlan((prev) => {
      if (!prev) return prev;
      const current = prev.sections[section];
      if (!current || !Array.isArray(current.items)) return prev;
      const items = current.items.filter((_, i) => i !== index);
      return {
        ...prev,
        sections: { ...prev.sections, [section]: { ...current, items } },
        plan: { ...prev.plan, [section]: items },
      };
    });
  }

  const reviewNotes = plan?.review ?? [];
  const needsReview = reviewNotes.length > 0;

  async function runApply() {
    if (!plan) return;
    // Rebuild the provisioner-ready plan from the (possibly edited) sections.
    const editedPlan: Record<string, unknown> = { ...plan.plan };
    for (const [name, section] of Object.entries(plan.sections)) {
      editedPlan[name] = section.items;
    }
    try {
      const result = await apply.mutateAsync({
        industry: plan.industry.key,
        plan: editedPlan,
        save: true,
        workforce_profile: plan.profiles?.workforce?.key,
        policy_profile: plan.profiles?.policy?.key,
      });
      const created = result.provisioned.total_created;
      toast.success(
        created > 0
          ? t(
              "setup2.applied",
              "Configuration applied: :count records created",
              { count: created },
            )
          : t(
              "setup2.applied_already",
              "Configuration applied. Already set up.",
            ),
      );
      if (result.provisioned.warnings.length > 0) {
        toast.warning(result.provisioned.warnings.join(" "));
      }
      onApplied?.();
    } catch {
      toast.error(t("setup2.apply_failed", "Could not apply configuration"));
    }
  }

  function profileSelect(
    id: string,
    title: string,
    options: ConfigurationProfile[],
    value: string | undefined,
    onChange: (key: string) => void,
  ) {
    const current = options.find((o) => o.key === value);
    return (
      <div>
        <Label htmlFor={id}>{title}</Label>
        <Select value={value} onValueChange={onChange}>
          <SelectTrigger id={id} className="mt-1">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {options.map((o) => (
              <SelectItem key={o.key} value={o.key}>
                {label(o)}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
        {current && (
          <p className="mt-1 text-xs text-muted-foreground">
            {locale === "am" && current.description_am
              ? current.description_am
              : current.description}
          </p>
        )}
      </div>
    );
  }

  const attendance = plan?.sections.attendance;
  const attendanceItems =
    attendance &&
    attendance.items &&
    typeof attendance.items === "object" &&
    !Array.isArray(attendance.items)
      ? Object.entries(attendance.items as Record<string, unknown>)
      : [];

  const sectionTitle = (section: string) => {
    const [key, fallback] = SECTION_TITLES[section] ?? [
      section,
      section.replace(/_/g, " "),
    ];
    return t(key, fallback);
  };

  const industryName = plan
    ? locale === "am"
      ? plan.industry.label_am
      : plan.industry.label
    : "";

  return (
    <div className="space-y-8">
      <header>
        <h2 className="text-lg font-semibold text-foreground">
          {t("setup2.config_title", "Organisation setup")}
        </h2>
        <p className="mt-1 max-w-2xl text-sm text-muted-foreground">
          {t(
            "setup2.config_desc",
            "Choose the kind of organisation you run. ETHR proposes departments, shifts, leave and pay settings for you to review. Nothing is created until you apply.",
          )}
        </p>
      </header>

      {!plan && (
        <QueryBoundary query={industriesQuery}>
          {(_industries) => (
            <div className="space-y-8">
              <section aria-labelledby="org-type-heading" className="space-y-4">
                <h3
                  id="org-type-heading"
                  className="text-sm font-medium text-foreground"
                >
                  {t("setup2.org_type", "Type of organisation")}
                </h3>
                {Object.entries(grouped).map(([group, items]) => (
                  <div
                    key={group}
                    role="radiogroup"
                    aria-label={t(
                      `setup2.group_${group}`,
                      group.replace(/_/g, " "),
                    )}
                  >
                    <p className="mb-2 text-xs font-medium text-muted-foreground">
                      {t(`setup2.group_${group}`, group.replace(/_/g, " "))}
                    </p>
                    <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                      {(items ?? []).map((ind) => {
                        const isSel = selected === ind.key;
                        return (
                          <button
                            key={ind.key}
                            type="button"
                            role="radio"
                            aria-checked={isSel}
                            onClick={() => chooseIndustry(ind.key)}
                            className={cn(
                              "flex min-h-11 items-center justify-between gap-2 rounded-md border px-3 py-2 text-left text-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
                              isSel
                                ? "border-primary bg-primary/5 text-foreground"
                                : "border-border text-foreground hover:bg-muted/50",
                            )}
                          >
                            <span className={cn(isSel && "font-medium")}>
                              {locale === "am" ? ind.label_am : ind.label}
                            </span>
                            {isSel && (
                              <Check
                                className="h-4 w-4 shrink-0 text-primary"
                                aria-hidden="true"
                              />
                            )}
                          </button>
                        );
                      })}
                    </div>
                  </div>
                ))}
                {ofSignupType.length > 0 && (
                  <Button
                    type="button"
                    variant="link"
                    className="h-auto px-0 text-sm"
                    onClick={() => setShowAllTypes((all) => !all)}
                  >
                    {narrowed
                      ? t(
                          "setup2.show_all_types",
                          "Show every type of organisation",
                        )
                      : t(
                          "setup2.show_signup_types",
                          "Show only the types that match your sign-up",
                        )}
                  </Button>
                )}
              </section>

              {selectedIndustry && (
                <section
                  aria-labelledby="operating-heading"
                  className="space-y-4 border-t pt-6"
                >
                  <h3
                    id="operating-heading"
                    className="text-sm font-medium text-foreground"
                  >
                    {t("setup2.how_you_operate", "How you operate")}
                  </h3>
                  <div className="grid gap-x-6 gap-y-5 sm:grid-cols-2">
                    {workforceOptions.length > 0 &&
                      profileSelect(
                        "workforce-profile",
                        t("setup2.workforce_profile", "How your people work"),
                        workforceOptions,
                        workforceProfile ?? selectedIndustry.profile,
                        setWorkforceProfile,
                      )}
                    {policyOptions.length > 0 &&
                      profileSelect(
                        "policy-profile",
                        t("setup2.policy_profile", "Which rules apply"),
                        policyOptions,
                        policyProfile ?? selectedIndustry.policy,
                        setPolicyProfile,
                      )}
                    <div>
                      <Label htmlFor="employee-count">
                        {t("setup2.employee_count", "Employees (approx.)")}
                      </Label>
                      <Input
                        id="employee-count"
                        type="number"
                        min={1}
                        inputMode="numeric"
                        value={employeeCount}
                        onChange={(e) => setEmployeeCount(e.target.value)}
                        className="mt-1"
                      />
                    </div>
                    <div>
                      <Label htmlFor="region">
                        {t("setup2.region", "Region")}
                      </Label>
                      <Input
                        id="region"
                        value={region}
                        onChange={(e) => setRegion(e.target.value)}
                        className="mt-1"
                        placeholder={t("setup.default_city", "Addis Ababa")}
                      />
                    </div>
                  </div>
                </section>
              )}

              <div className="flex justify-end border-t pt-6">
                <Button
                  onClick={runPreview}
                  disabled={!selected || preview.isPending}
                >
                  {preview.isPending && (
                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                  )}
                  {t("setup2.review_proposal", "Review proposal")}
                </Button>
              </div>
            </div>
          )}
        </QueryBoundary>
      )}

      {plan && (
        <div className="space-y-6">
          <div className="flex flex-wrap items-start justify-between gap-4 rounded-lg border bg-muted/30 px-4 py-3">
            <dl className="grid gap-x-8 gap-y-2 text-sm sm:grid-cols-3">
              <div>
                <dt className="text-xs text-muted-foreground">
                  {t("setup2.org_type", "Type of organisation")}
                </dt>
                <dd className="font-medium text-foreground">{industryName}</dd>
              </div>
              {plan.profiles?.workforce && (
                <div>
                  <dt className="text-xs text-muted-foreground">
                    {t("setup2.workforce_profile", "How your people work")}
                  </dt>
                  <dd className="font-medium text-foreground">
                    {label(plan.profiles.workforce)}
                  </dd>
                </div>
              )}
              {plan.profiles?.policy && (
                <div>
                  <dt className="text-xs text-muted-foreground">
                    {t("setup2.policy_profile", "Which rules apply")}
                  </dt>
                  <dd className="font-medium text-foreground">
                    {label(plan.profiles.policy)}
                  </dd>
                </div>
              )}
            </dl>
            <Button
              variant="outline"
              size="sm"
              onClick={() => setPlan(null)}
              disabled={apply.isPending}
            >
              {t("common.change", "Change")}
            </Button>
          </div>

          <div className="divide-y rounded-lg border">
            {LIST_SECTIONS.filter((s) =>
              Array.isArray(plan.sections[s]?.items),
            ).map((section) => {
              const sec = plan.sections[section];
              const items = sec.items as unknown[];
              return (
                <div
                  key={section}
                  className="grid gap-3 px-4 py-4 sm:grid-cols-[11rem_1fr] sm:gap-6"
                >
                  <div>
                    <p className="text-sm font-medium text-foreground">
                      {sectionTitle(section)}{" "}
                      <span className="font-normal text-muted-foreground tabular-nums">
                        {items.length}
                      </span>
                    </p>
                    <SourceLabel source={sec.source} />
                  </div>
                  <div className="min-w-0">
                    <ul className="flex flex-wrap gap-1.5">
                      {items.map((item, i) => (
                        <li
                          key={`${section}-${i}`}
                          className="inline-flex items-center gap-1 rounded-md border bg-background py-1 pl-2 pr-1 text-xs text-foreground"
                        >
                          {itemLabel(section, item)}
                          <button
                            type="button"
                            onClick={() => removeItem(section, i)}
                            aria-label={`${t("setup2.remove", "Remove")} ${itemLabel(section, item)}`}
                            className="rounded p-0.5 text-muted-foreground hover:bg-muted hover:text-foreground"
                          >
                            <X className="h-3 w-3" aria-hidden="true" />
                          </button>
                        </li>
                      ))}
                    </ul>
                    {items.length === 0 && (
                      <span className="text-xs text-muted-foreground">
                        {t("setup2.none", "None")}
                      </span>
                    )}
                    {section === "allowances" && items.length > 0 && (
                      <p className="mt-2 text-xs text-muted-foreground">
                        {t(
                          "setup2.allowances_note",
                          "Created switched off with no amount. Nobody's pay changes until you set one.",
                        )}
                      </p>
                    )}
                  </div>
                </div>
              );
            })}

            {attendance && attendanceItems.length > 0 && (
              <div className="grid gap-3 px-4 py-4 sm:grid-cols-[11rem_1fr] sm:gap-6">
                <div>
                  <p className="text-sm font-medium text-foreground">
                    {t("setup2.section_attendance", "Attendance settings")}
                  </p>
                  <SourceLabel source={attendance.source} />
                </div>
                <div className="min-w-0">
                  <ul className="space-y-1 text-sm text-foreground">
                    {attendanceItems.map(([key, value]) => (
                      <li key={key}>{attendanceLabel(key, value)}</li>
                    ))}
                  </ul>
                  <p className="mt-2 text-xs text-muted-foreground">
                    {t(
                      "setup2.attendance_note",
                      "Only settings you have not changed yourself are set.",
                    )}
                  </p>
                </div>
              </div>
            )}
          </div>

          {needsReview && (
            <section
              aria-labelledby="review-heading"
              className="rounded-lg border border-l-4 border-l-warning bg-card p-4"
            >
              <h3
                id="review-heading"
                className="flex items-center gap-2 text-sm font-semibold text-foreground"
              >
                <ShieldAlert className="h-4 w-4" aria-hidden="true" />
                {t("setup2.review_title", "Confirm before you apply")}
              </h3>
              <ul className="mt-2 list-disc space-y-1.5 pl-5 text-sm text-muted-foreground">
                {reviewNotes.map((note) => (
                  <li key={note.code}>{note.message}</li>
                ))}
              </ul>
              <label className="mt-4 flex items-start gap-2 text-sm text-foreground">
                <Checkbox
                  checked={reviewed}
                  onCheckedChange={(v) => setReviewed(v === true)}
                  aria-label={t(
                    "setup2.review_confirm",
                    "I have read these and will confirm the values",
                  )}
                />
                <span>
                  {t(
                    "setup2.review_confirm",
                    "I have read these and will confirm the values",
                  )}
                </span>
              </label>
            </section>
          )}

          <div className="flex flex-col-reverse gap-3 border-t pt-6 sm:flex-row sm:items-center sm:justify-between">
            <p className="text-xs text-muted-foreground">
              {t(
                "setup2.review_note",
                "Nothing is created until you apply. You can edit everything later.",
              )}
            </p>
            <Button
              onClick={runApply}
              disabled={apply.isPending || (needsReview && !reviewed)}
            >
              {apply.isPending && (
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
              )}
              {t("setup2.apply_configuration", "Apply configuration")}
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}
