"use client";

import type { ReactNode } from "react";
import Link from "next/link";
import {
  AlertTriangle,
  Mail,
  Package,
  RefreshCw,
  Server,
  Timer,
  XCircle,
} from "lucide-react";
import { useQueryClient } from "@tanstack/react-query";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { Button } from "@/components/ui/button";
import { RoleGate } from "@/components/shared/role-gate";
import { PageActions } from "@/components/shared/page-actions";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import { useT } from "@/lib/i18n/useT";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { cn } from "@/lib/utils";
import {
  areasNeedingAttention,
  useAdminOperations,
  type OperationsArea,
  type OperationsState,
  type OperationsStatus,
} from "@/features/admin/operations";
import {
  StateBadge,
  areaLabel,
  reasonLabel,
} from "@/features/admin/components/operations-labels";

type Translate = ReturnType<typeof useT>["t"];

/**
 * The operations centre, phase 1: what the platform already records about
 * whether it is doing its work, in one place. See
 * docs/operations/OPERATIONS-CENTRE-SPEC.md for what each state means and
 * what later phases add.
 */
export default function OperationsPage() {
  const { t } = useT();
  const queryClient = useQueryClient();
  const query = useAdminOperations();

  return (
    <RoleGate minRole="super_admin">
      <div className="space-y-6">
        <PageActions
          actions={
            <Button
              variant="outline"
              size="sm"
              onClick={() =>
                queryClient.invalidateQueries({
                  queryKey: ["admin", "operations"],
                })
              }
            >
              <RefreshCw className="mr-1.5 h-3.5 w-3.5" />
              {t("operations_page.refresh", "Check again")}
            </Button>
          }
        />
        <QueryBoundary
          query={query}
          loading={<Skeleton className="h-96 w-full" />}
          isEmpty={() => false}
        >
          {(status) => <OperationsView status={status} t={t} />}
        </QueryBoundary>
      </div>
    </RoleGate>
  );
}

function OperationsView({
  status,
  t,
}: {
  status: OperationsStatus;
  t: Translate;
}) {
  const { timeAgo } = useDateFormatters();
  const attention = areasNeedingAttention(status);
  const when = (iso: string | null) =>
    iso === null ? t("operations_page.never", "Never") : timeAgo(iso);

  return (
    <div className="space-y-6">
      <Summary status={status} attention={attention} t={t} />

      <Panel
        id="background"
        icon={<Timer className="h-4 w-4" />}
        state={status.background.state}
        reasons={status.background.reasons}
        t={t}
      >
        <Rows>
          <Row
            label={t("operations_page.scheduler", "Scheduler last ran")}
            value={when(status.background.scheduler.last_run_at)}
            state={status.background.scheduler.state}
            t={t}
          />
          <Row
            label={t(
              "operations_page.external_caller",
              "External cron caller last called",
            )}
            value={when(status.background.external_caller.last_run_at)}
            state={status.background.external_caller.state}
            t={t}
          />
          <Row
            label={t(
              "operations_page.page_traffic",
              "Page traffic last ran background work",
            )}
            value={when(status.background.page_traffic.last_run_at)}
          />
          <Row
            label={t("operations_page.last_drain", "Queue last drained")}
            value={when(status.background.last_drain_at)}
          />
        </Rows>
        <div className="mt-4 overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b text-left text-xs uppercase tracking-wider text-muted-foreground">
                <th className="py-2 pr-3 font-medium">
                  {t("operations_page.queue", "Queue")}
                </th>
                <th className="py-2 pr-3 text-right font-medium">
                  {t("operations_page.depth", "Waiting jobs")}
                </th>
                <th className="py-2 pr-3 text-right font-medium">
                  {t("operations_page.oldest", "Oldest has waited")}
                </th>
                <th className="py-2 text-right font-medium">
                  {t("operations_page.state_column", "State")}
                </th>
              </tr>
            </thead>
            <tbody>
              {status.background.queues.map((queue) => (
                <tr key={queue.name} className="border-b last:border-0">
                  <td className="py-2 pr-3 font-mono text-xs text-foreground">
                    {queue.name}
                  </td>
                  <td className="py-2 pr-3 text-right tabular-nums">
                    {queue.depth ?? "?"}
                  </td>
                  <td className="py-2 pr-3 text-right tabular-nums text-muted-foreground">
                    {queue.oldest_waiting_seconds === null
                      ? t("operations_page.nothing_waiting", "Nothing waiting")
                      : duration(t, queue.oldest_waiting_seconds)}
                  </td>
                  <td className="py-2 text-right">
                    <StateBadge state={queue.state} t={t} />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Panel>

      <Panel
        id="delivery"
        icon={<Mail className="h-4 w-4" />}
        state={status.delivery.state}
        reasons={status.delivery.reasons}
        t={t}
      >
        <Rows>
          <Row
            label={t("operations_page.mailer", "Mailer")}
            value={<code>{status.delivery.mail.mailer}</code>}
          />
          <Row
            label={t("operations_page.last_sent", "Last email sent")}
            value={when(status.delivery.mail.last_sent_at)}
          />
          <Row
            label={t("operations_page.last_failed", "Last email failed")}
            value={when(status.delivery.mail.last_failed_at)}
          />
          <Row
            label={t("operations_page.tls", "How the mail server was trusted")}
            value={status.delivery.mail.tls ?? "?"}
          />
        </Rows>
      </Panel>

      <Panel
        id="release"
        icon={<Package className="h-4 w-4" />}
        state={status.release.state}
        reasons={status.release.reasons}
        t={t}
      >
        <Rows>
          <Row
            label={t("operations_page.commit", "Commit")}
            value={
              status.release.commit === null ? (
                "?"
              ) : (
                <code>{status.release.commit.slice(0, 12)}</code>
              )
            }
          />
          <Row
            label={t("operations_page.built", "Built")}
            value={when(status.release.built_at)}
          />
          <Row
            label={t("operations_page.environment", "Environment")}
            value={<code>{status.release.environment}</code>}
          />
          <Row
            label={t(
              "operations_page.pending_migrations",
              "Pending migrations",
            )}
            value={status.release.pending_migrations ?? "?"}
          />
          <Row
            label={t("operations_page.config_cached", "Configuration cached")}
            value={when(status.release.config_cached_at)}
          />
          <Row
            label={t("operations_page.routes_cached", "Routes cached")}
            value={when(status.release.routes_cached_at)}
          />
          <Row
            label={t(
              "operations_page.maintenance_routes",
              "Maintenance routes",
            )}
            value={
              status.release.maintenance_routes_open
                ? t("operations_page.open_state", "Open")
                : t("operations_page.closed_state", "Closed")
            }
          />
          <Row
            label={t("operations_page.cron_token", "Cron token set")}
            value={yesNo(t, status.release.cron_token_set)}
          />
          <Row
            label={t("operations_page.debug", "Debug mode")}
            value={yesNo(t, status.release.debug)}
          />
          <Row
            label={t("operations_page.queue_connection", "Queue connection")}
            value={<code>{status.release.queue_connection}</code>}
          />
          <Row
            label={t(
              "operations_page.broadcast_connection",
              "Broadcast connection",
            )}
            value={<code>{status.release.broadcast_connection}</code>}
          />
          <Row
            label={t("operations_page.php", "PHP limits")}
            value={
              <code className="text-xs">
                {status.release.php.version} · upload{" "}
                {status.release.php.upload_max_filesize} · post{" "}
                {status.release.php.post_max_size} · memory{" "}
                {status.release.php.memory_limit} · time{" "}
                {status.release.php.max_execution_time}s
              </code>
            }
          />
        </Rows>
      </Panel>

      <Panel
        id="failed_jobs"
        icon={<XCircle className="h-4 w-4" />}
        state={status.failed_jobs.state}
        reasons={status.failed_jobs.reasons}
        t={t}
        action={
          <Button variant="ghost" size="sm" asChild>
            <Link href="/admin">
              {t("operations_page.review_failed", "Review failed jobs")}
            </Link>
          </Button>
        }
      >
        <Rows>
          <Row
            label={t("operations_page.failed_count", "Failed jobs")}
            value={status.failed_jobs.count ?? "?"}
          />
          <Row
            label={t("operations_page.last_job_failed", "Last job failed")}
            value={when(status.failed_jobs.last_failed_at)}
          />
        </Rows>
      </Panel>

      <Panel
        id="services"
        icon={<Server className="h-4 w-4" />}
        state={status.services.state}
        reasons={status.services.reasons}
        t={t}
      >
        <Rows>
          {status.services.checks.map((check) => (
            <Row
              key={check.name}
              label={<span className="capitalize">{check.name}</span>}
              value={
                <span className="flex items-center justify-end gap-2">
                  {check.response_ms !== null && check.response_ms > 0 && (
                    <span className="tabular-nums text-muted-foreground">
                      {check.response_ms} ms
                    </span>
                  )}
                  <code>{check.status}</code>
                </span>
              }
            />
          ))}
          <Row
            label={t("operations_page.disk_free", "Disk free")}
            value={
              status.services.disk_free_gb === null
                ? "?"
                : `${status.services.disk_free_gb} GB`
            }
          />
        </Rows>
      </Panel>
    </div>
  );
}

function Summary({
  status,
  attention,
  t,
}: {
  status: OperationsStatus;
  attention: OperationsArea[];
  t: Translate;
}) {
  const { timeAgo } = useDateFormatters();

  return (
    <Card>
      <CardContent className="flex flex-wrap items-center justify-between gap-4 pt-6">
        <div className="space-y-1">
          <div className="flex items-center gap-2">
            <StateBadge state={status.status} t={t} />
            <p className="text-sm font-semibold text-foreground">
              {attention.length === 0
                ? t("operations_page.summary_clear", "Nothing needs attention")
                : attention.length === 1
                  ? t(
                      "operations_page.summary_attention_one",
                      "One area needs attention",
                    )
                  : t(
                      "operations_page.summary_attention",
                      ":count areas need attention",
                      { count: attention.length },
                    )}
            </p>
          </div>
          <p className="text-xs text-muted-foreground">
            {t("operations_page.checked", "Checked :when", {
              when: timeAgo(status.checked_at),
            })}
          </p>
        </div>
        <nav className="flex flex-wrap gap-2">
          {attention.map((area) => (
            <a
              key={area}
              href={`#${area}`}
              className="rounded-full border px-3 py-1 text-xs font-medium text-foreground hover:bg-muted/50"
            >
              {areaLabel(t, area)}
            </a>
          ))}
        </nav>
      </CardContent>
    </Card>
  );
}

function Panel({
  id,
  icon,
  state,
  reasons,
  t,
  action,
  children,
}: {
  id: OperationsArea;
  icon: ReactNode;
  state: OperationsState;
  reasons: string[];
  t: Translate;
  action?: ReactNode;
  children: ReactNode;
}) {
  return (
    <Card id={id} className="scroll-mt-20">
      <CardHeader className="flex flex-row items-center justify-between gap-2 pb-3">
        <CardTitle className="flex items-center gap-2 text-base">
          {icon}
          {areaLabel(t, id)}
          <StateBadge state={state} t={t} />
        </CardTitle>
        {action}
      </CardHeader>
      <CardContent className="space-y-4">
        {reasons.length > 0 && (
          <ul className="space-y-1.5">
            {reasons.map((code) => (
              <li
                key={code}
                className={cn(
                  "flex items-start gap-2 text-sm",
                  state === "critical" || state === "warning"
                    ? "text-foreground"
                    : "text-muted-foreground",
                )}
              >
                <AlertTriangle
                  className={cn(
                    "mt-0.5 h-4 w-4 shrink-0",
                    state === "critical"
                      ? "text-status-error"
                      : state === "warning"
                        ? "text-status-warning"
                        : "text-muted-foreground",
                  )}
                />
                {reasonLabel(t, code)}
              </li>
            ))}
          </ul>
        )}
        {children}
      </CardContent>
    </Card>
  );
}

function Rows({ children }: { children: ReactNode }) {
  return <dl className="divide-y text-sm">{children}</dl>;
}

function Row({
  label,
  value,
  state,
  t,
}: {
  label: ReactNode;
  value: ReactNode;
  state?: OperationsState;
  t?: Translate;
}) {
  return (
    <div className="flex items-center justify-between gap-4 py-2">
      <dt className="text-muted-foreground">{label}</dt>
      <dd className="flex items-center gap-2 text-right text-foreground">
        {value}
        {state !== undefined && t !== undefined && (
          <StateBadge state={state} t={t} />
        )}
      </dd>
    </div>
  );
}

function yesNo(t: Translate, value: boolean): string {
  return value
    ? t("operations_page.yes", "Yes")
    : t("operations_page.no", "No");
}

function duration(t: Translate, seconds: number): string {
  if (seconds < 120) {
    return t("operations_page.seconds", ":count s", { count: seconds });
  }
  if (seconds < 7200) {
    return t("operations_page.minutes", ":count min", {
      count: Math.round(seconds / 60),
    });
  }

  return t("operations_page.hours", ":count h", {
    count: Math.round(seconds / 3600),
  });
}
