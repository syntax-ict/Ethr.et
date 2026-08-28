"use client";

import Link from "next/link";
import {
  Rocket,
  Loader2,
  CheckCircle2,
  Circle,
  ArrowRight,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Progress } from "@/components/ui/progress";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";
import { useReadiness, useGoLive } from "../api";
import type { ReadinessLevel } from "../types";

function levelTone(level: ReadinessLevel): string {
  return {
    ready: "var(--color-status-success, #059669)",
    needs_attention: "var(--color-status-warning, #D97706)",
    not_ready: "var(--color-status-error, #DC2626)",
  }[level];
}

export function ReadinessStep({ onLive }: { onLive?: () => void }) {
  const { t } = useT();
  const readinessQuery = useReadiness();
  const goLive = useGoLive();

  async function launch() {
    try {
      await goLive.mutateAsync();
      toast.success(t("setup2.went_live", "Your organization is live!"));
      onLive?.();
    } catch {
      toast.error(t("setup2.golive_failed", "Could not complete go-live"));
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center gap-3">
        <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-primary/10">
          <Rocket className="h-5 w-5 text-primary" />
        </div>
        <div>
          <h2 className="text-xl font-bold text-foreground">
            {t("setup2.readiness_title", "Readiness & go live")}
          </h2>
          <p className="text-sm text-muted-foreground">
            {t(
              "setup2.readiness_desc",
              "A quick check of how ready your workspace is. Fix the gaps or launch anyway.",
            )}
          </p>
        </div>
      </div>

      <QueryBoundary query={readinessQuery}>
        {(report) => (
          <div className="space-y-5">
            <div className="rounded-xl border p-5">
              <div className="flex items-center justify-between">
                <span className="text-sm text-muted-foreground">
                  {t("setup2.readiness_score", "Readiness score")}
                </span>
                <span
                  className="text-2xl font-bold"
                  style={{ color: levelTone(report.level) }}
                >
                  {report.overall_score}%
                </span>
              </div>
              <Progress
                value={report.overall_score}
                className="mt-3 h-2"
                label={t("onboarding.readiness_score", "Readiness score")}
              />
              <p
                className="mt-2 text-xs font-medium"
                style={{ color: levelTone(report.level) }}
              >
                {t(
                  `setup2.level_${report.level}`,
                  report.level.replace(/_/g, " "),
                )}
              </p>
            </div>

            <div className="grid gap-3 sm:grid-cols-3">
              {Object.entries(report.categories).map(([name, cat]) => (
                <Card key={name}>
                  <CardContent className="p-4">
                    <p className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                      {t(`setup2.cat_${name}`, name)}
                    </p>
                    <p className="mt-1 text-lg font-bold text-foreground">
                      {cat.score}%
                    </p>
                    <ul className="mt-2 space-y-1">
                      {cat.checks.map((c) => (
                        <li
                          key={c.id}
                          className="flex items-center gap-1.5 text-xs text-muted-foreground"
                        >
                          {c.passed ? (
                            <CheckCircle2
                              className="h-3.5 w-3.5 shrink-0"
                              style={{
                                color: "var(--color-status-success, #059669)",
                              }}
                            />
                          ) : (
                            <Circle className="h-3.5 w-3.5 shrink-0" />
                          )}
                          <span className={c.passed ? "" : "text-foreground"}>
                            {c.label}
                          </span>
                        </li>
                      ))}
                    </ul>
                  </CardContent>
                </Card>
              ))}
            </div>

            {report.gaps.length > 0 && (
              <div className="rounded-lg border bg-muted/30 p-4">
                <p className="mb-2 text-sm font-semibold text-foreground">
                  {t("setup2.gaps", "To improve your score")}
                </p>
                <ul className="space-y-1.5">
                  {report.gaps.map((gap) => (
                    <li
                      key={gap.id}
                      className="flex items-center justify-between gap-2"
                    >
                      <span className="text-sm text-muted-foreground">
                        {gap.label}
                      </span>
                      <Button
                        asChild
                        variant="ghost"
                        size="sm"
                        className="h-7 text-xs"
                      >
                        <Link href={gap.remediation}>
                          {t("setup2.fix", "Fix")}
                          <ArrowRight className="ml-1 h-3 w-3" />
                        </Link>
                      </Button>
                    </li>
                  ))}
                </ul>
              </div>
            )}

            <div className="flex justify-end border-t pt-4">
              <Button size="lg" onClick={launch} disabled={goLive.isPending}>
                {goLive.isPending ? (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                ) : (
                  <Rocket className="mr-2 h-4 w-4" />
                )}
                {t("setup2.go_live", "Go live")}
              </Button>
            </div>
          </div>
        )}
      </QueryBoundary>
    </div>
  );
}
