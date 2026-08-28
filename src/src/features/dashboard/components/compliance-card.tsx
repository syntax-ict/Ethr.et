"use client";

import { useState } from "react";
import Link from "next/link";
import {
  FileWarning,
  UserX,
  CalendarOff,
  ShieldCheck,
  Settings,
  AlertTriangle,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import { Skeleton } from "@/components/ui/skeleton";
import {
  useExecutiveCompliance,
  useTriggeredAlerts,
} from "@/features/dashboard/executive-api";
import { AlertThresholdsDialog } from "@/features/dashboard/components/alert-thresholds-dialog";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useT } from "@/lib/i18n/useT";

/**
 * Real, defensible compliance signals — not a fabricated "score". Each figure
 * is a factual count from data the platform already tracks, with a link to
 * where it can be acted on. See ExecutiveDashboardService::complianceSnapshot().
 */
export function ComplianceCard({
  branchPublicId,
}: {
  branchPublicId?: string;
}) {
  const { t } = useT();
  const { can } = usePermissions();
  const query = useExecutiveCompliance(branchPublicId);
  const alertsQuery = useTriggeredAlerts(branchPublicId);
  const [configOpen, setConfigOpen] = useState(false);

  const alerts = alertsQuery.data?.alerts ?? [];

  return (
    <Card>
      <CardHeader className="flex flex-row items-center justify-between space-y-0">
        <CardTitle className="flex items-center gap-2 text-base">
          <ShieldCheck className="h-4 w-4" />
          {t("executive_dashboard.compliance", "Compliance")}
        </CardTitle>
        {can.viewExecutiveDashboard && (
          <Button
            variant="ghost"
            size="icon"
            className="h-7 w-7 text-muted-foreground"
            onClick={() => setConfigOpen(true)}
            aria-label={t("alert_dialog.configure", "Configure alerts")}
          >
            <Settings className="h-4 w-4" />
          </Button>
        )}
      </CardHeader>
      <CardContent>
        {alerts.length > 0 && (
          <div className="mb-3 space-y-1.5">
            {alerts.map((a) => (
              <div
                key={a.public_id}
                className="flex items-center gap-2 rounded-lg border border-status-error/30 bg-destructive-soft px-3 py-2 text-xs text-destructive-on-soft"
              >
                <AlertTriangle className="h-3.5 w-3.5 shrink-0" />
                <span>
                  {t(`alert_dialog.metric_${a.metric}`, a.metric)}:{" "}
                  <span className="font-mono font-semibold">
                    {a.current_value}
                  </span>{" "}
                  ({a.operator === "gt" ? ">" : "<"} {a.threshold_value})
                </span>
                <Badge
                  variant="outline"
                  className="ml-auto shrink-0 text-[10px] capitalize"
                >
                  {a.severity}
                </Badge>
              </div>
            ))}
          </div>
        )}

        {configOpen && (
          <AlertThresholdsDialog
            open={configOpen}
            onClose={() => setConfigOpen(false)}
          />
        )}
        <QueryBoundary
          query={query}
          loading={
            <div className="grid gap-3 sm:grid-cols-3">
              {Array.from({ length: 3 }).map((_, i) => (
                <Skeleton key={i} className="h-16" />
              ))}
            </div>
          }
        >
          {(data) => {
            const rows = [
              {
                key: "docs",
                icon: FileWarning,
                label: t(
                  "executive_dashboard.expiring_documents",
                  "Documents expiring within 30 days",
                ),
                count: data.expiring_documents.count,
                tone: data.expiring_documents.count > 0 ? "warning" : "ok",
                href: "/employees",
              },
              {
                key: "probation",
                icon: UserX,
                label: t(
                  "executive_dashboard.probation_overdue",
                  "Probation ended, no decision recorded",
                ),
                count: data.probation_overdue.count,
                tone: data.probation_overdue.count > 0 ? "error" : "ok",
                href: "/employees",
              },
              ...(data.unused_leave.applicable
                ? [
                    {
                      key: "leave",
                      icon: CalendarOff,
                      label: t(
                        "executive_dashboard.unused_leave",
                        "No leave taken this year",
                      ),
                      count: data.unused_leave.count,
                      tone:
                        data.unused_leave.count > 0
                          ? ("warning" as const)
                          : ("ok" as const),
                      href: "/leave",
                    },
                  ]
                : []),
            ];

            const toneClass: Record<string, string> = {
              ok: "bg-success-soft text-success-on-soft",
              warning: "bg-warning-soft text-warning-on-soft",
              error: "bg-destructive-soft text-destructive-on-soft",
            };

            return (
              <div className="grid gap-3 sm:grid-cols-3">
                {rows.map((row) => {
                  const Icon = row.icon;
                  return (
                    <Link
                      key={row.key}
                      href={row.href}
                      className="flex items-start gap-3 rounded-lg border p-3 transition-colors hover:bg-muted/40"
                    >
                      <div
                        className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-lg ${toneClass[row.tone]}`}
                      >
                        <Icon className="h-4 w-4" />
                      </div>
                      <div className="min-w-0">
                        <p className="font-mono text-lg font-semibold tabular-nums">
                          {row.count}
                        </p>
                        <p className="text-xs leading-tight text-muted-foreground">
                          {row.label}
                        </p>
                      </div>
                    </Link>
                  );
                })}
              </div>
            );
          }}
        </QueryBoundary>
      </CardContent>
    </Card>
  );
}
