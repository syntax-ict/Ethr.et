"use client";

import { useMemo, useState } from "react";
import {
  Sparkles,
  ChevronRight,
  Loader2,
  X,
  Check,
  AlertTriangle,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Badge } from "@/components/ui/badge";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import { cn } from "@/lib/utils";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";
import {
  useApplyConfiguration,
  useIndustries,
  usePreviewConfiguration,
} from "../api";
import type { ConfigurationPlan } from "../types";
import { ConfidenceBadge } from "./confidence-badge";

const LIST_SECTIONS = [
  "branches",
  "departments",
  "positions",
  "grades",
  "shifts",
  "leave_types",
] as const;

function itemLabel(item: unknown): string {
  if (typeof item === "string") return item;
  if (item && typeof item === "object") {
    const o = item as Record<string, unknown>;
    return String(o.name ?? o.title ?? o.code ?? JSON.stringify(o));
  }
  return String(item);
}

export function IndustryConfigStep({ onApplied }: { onApplied?: () => void }) {
  const { t, locale } = useT();
  const industriesQuery = useIndustries();
  const preview = usePreviewConfiguration();
  const apply = useApplyConfiguration();

  const [selected, setSelected] = useState<string | null>(null);
  const [employeeCount, setEmployeeCount] = useState("");
  const [region, setRegion] = useState("");
  const [plan, setPlan] = useState<ConfigurationPlan | null>(null);

  const grouped = useMemo(() => {
    const groups: Record<string, typeof industriesQuery.data> = {};
    for (const ind of industriesQuery.data ?? []) {
      (groups[ind.group] ??= []).push(ind);
    }
    return groups;
  }, [industriesQuery]);

  async function runPreview() {
    if (!selected) return;
    try {
      const result = await preview.mutateAsync({
        industry: selected,
        employee_count: employeeCount ? Number(employeeCount) : undefined,
        region: region || undefined,
      });
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
      });
      const created = result.provisioned.total_created;
      toast.success(
        created > 0
          ? t(
              "setup2.applied",
              "Configuration applied — {{n}} records created",
            ).replace("{{n}}", String(created))
          : t(
              "setup2.applied_already",
              "Configuration applied — already set up",
            ),
      );
      onApplied?.();
    } catch {
      toast.error(t("setup2.apply_failed", "Could not apply configuration"));
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center gap-3">
        <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-primary/10">
          <Sparkles className="h-5 w-5 text-primary" />
        </div>
        <div>
          <h2 className="text-xl font-bold text-foreground">
            {t("setup2.smart_config", "Smart configuration")}
          </h2>
          <p className="text-sm text-muted-foreground">
            {t(
              "setup2.smart_config_desc",
              "Pick your industry and we'll propose a full setup you can review and edit.",
            )}
          </p>
        </div>
      </div>

      {!plan && (
        <QueryBoundary query={industriesQuery}>
          {(_industries) => (
            <div className="space-y-6">
              <div className="grid gap-4 sm:grid-cols-3">
                <div>
                  <Label>
                    {t("setup2.employee_count", "Employees (approx.)")}
                  </Label>
                  <Input
                    type="number"
                    min={1}
                    value={employeeCount}
                    onChange={(e) => setEmployeeCount(e.target.value)}
                    className="mt-1"
                    placeholder="120"
                  />
                </div>
                <div className="sm:col-span-2">
                  <Label>{t("setup2.region", "Region")}</Label>
                  <Input
                    value={region}
                    onChange={(e) => setRegion(e.target.value)}
                    className="mt-1"
                    placeholder="Addis Ababa"
                  />
                </div>
              </div>

              <div className="space-y-5">
                {Object.entries(grouped).map(([group, items]) => (
                  <div key={group}>
                    <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                      {t(`setup2.group_${group}`, group.replace(/_/g, " "))}
                    </p>
                    <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                      {(items ?? []).map((ind) => {
                        const isSel = selected === ind.key;
                        return (
                          <button
                            key={ind.key}
                            type="button"
                            onClick={() => setSelected(ind.key)}
                            className={cn(
                              "flex items-center justify-between rounded-lg border-2 p-3 text-left text-sm transition-all",
                              isSel
                                ? "border-primary bg-primary/5"
                                : "border-border hover:border-primary/50",
                            )}
                          >
                            <span className="font-medium text-foreground">
                              {locale === "am" ? ind.label_am : ind.label}
                            </span>
                            {isSel && (
                              <Check className="h-4 w-4 shrink-0 text-primary" />
                            )}
                          </button>
                        );
                      })}
                    </div>
                  </div>
                ))}
              </div>

              <div className="flex justify-end border-t pt-4">
                <Button
                  onClick={runPreview}
                  disabled={!selected || preview.isPending}
                >
                  {preview.isPending ? (
                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                  ) : null}
                  {t("setup2.generate", "Generate configuration")}
                  <ChevronRight className="ml-1 h-4 w-4" />
                </Button>
              </div>
            </div>
          )}
        </QueryBoundary>
      )}

      {plan && (
        <div className="space-y-5">
          <div className="flex flex-wrap items-center justify-between gap-2 rounded-lg border bg-muted/30 p-3">
            <div className="text-sm">
              <span className="text-muted-foreground">
                {t("setup2.overall_confidence", "Overall confidence")}:
              </span>{" "}
              <span className="font-semibold text-foreground">
                {Math.round(plan.overall_confidence * 100)}%
              </span>
            </div>
            <Button
              variant="ghost"
              size="sm"
              onClick={() => setPlan(null)}
              disabled={apply.isPending}
            >
              {t("setup2.change_industry", "Change industry")}
            </Button>
          </div>

          <div className="space-y-4">
            {LIST_SECTIONS.filter((s) =>
              Array.isArray(plan.sections[s]?.items),
            ).map((section) => {
              const sec = plan.sections[section];
              const items = sec.items as unknown[];
              return (
                <Card key={section}>
                  <CardContent className="p-4">
                    <div className="mb-2 flex items-center justify-between">
                      <p className="text-sm font-semibold capitalize text-foreground">
                        {t(
                          `setup2.section_${section}`,
                          section.replace(/_/g, " "),
                        )}{" "}
                        <span className="text-muted-foreground">
                          ({items.length})
                        </span>
                      </p>
                      <ConfidenceBadge
                        source={sec.source}
                        confidence={sec.confidence}
                      />
                    </div>
                    <div className="flex flex-wrap gap-1.5">
                      {items.map((item, i) => (
                        <Badge
                          key={`${section}-${i}`}
                          variant="secondary"
                          className="gap-1 py-1"
                        >
                          {itemLabel(item)}
                          <button
                            type="button"
                            onClick={() => removeItem(section, i)}
                            aria-label={t("setup2.remove", "Remove")}
                            className="ml-0.5 rounded-full hover:text-destructive"
                          >
                            <X className="h-3 w-3" />
                          </button>
                        </Badge>
                      ))}
                      {items.length === 0 && (
                        <span className="text-xs text-muted-foreground">
                          {t("setup2.none", "None")}
                        </span>
                      )}
                    </div>
                  </CardContent>
                </Card>
              );
            })}
          </div>

          <div className="flex items-center justify-between gap-2 border-t pt-4">
            <p className="flex items-center gap-1 text-xs text-muted-foreground">
              <AlertTriangle className="h-3 w-3" />
              {t(
                "setup2.review_note",
                "Nothing is created until you apply. You can edit everything later.",
              )}
            </p>
            <Button onClick={runApply} disabled={apply.isPending}>
              {apply.isPending ? (
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
              ) : null}
              {t("setup2.accept_apply", "Accept & apply")}
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}
