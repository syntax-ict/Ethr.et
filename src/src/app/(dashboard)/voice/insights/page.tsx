"use client";

import { useState } from "react";
import {
  CheckCircle2,
  EyeOff,
  Gauge,
  Inbox,
  Loader2,
  MessageSquareWarning,
  ShieldCheck,
  Users,
} from "lucide-react";
import {
  Area,
  AreaChart,
  CartesianGrid,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";
import { toast } from "sonner";
import { useT } from "@/lib/i18n/useT";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { cn } from "@/lib/utils";
import { EmptyState } from "@/components/shared/empty-state";
import { PaginationControls } from "@/components/shared/pagination-controls";
import { ChartCard, StatCard } from "@/components/dashboard";
import {
  axisProps,
  gridProps,
  lineTooltipProps,
  seriesColor,
} from "@/components/dashboard/chart-theme";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { Textarea } from "@/components/ui/textarea";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Dialog, DialogContent, DialogTitle } from "@/components/ui/dialog";
import {
  CONCERN_STATUSES,
  useConcern,
  useConcernMessage,
  useConcerns,
  usePulseInsights,
  useUpdateConcern,
  type ConcernStatus,
} from "@/features/voice/api";
import { ConcernStatusBadge, ConcernThread } from "@/features/voice/components";
import { apiErrorMessage } from "@/lib/api/error-message";
import { ErrorState } from "@/components/shared/error-state";
import { useQueryFilters } from "@/lib/hooks/useQueryFilters";

/**
 * Employee voice for HR: how people say work is going, and the concerns they
 * raised. Pulse results appear only for groups of five or more, and an
 * anonymous concern never names its author - here or anywhere.
 */
export default function VoiceInsightsPage() {
  const { t } = useT();
  const { can } = usePermissions();

  if (!can.manageVoice) {
    return (
      <EmptyState
        icon={ShieldCheck}
        title={t("voice.no_access", "HR only")}
        description={t(
          "voice.no_access_desc",
          "Pulse results and concerns are visible to HR.",
        )}
      />
    );
  }

  return (
    <div className="space-y-6">
      <PulseOverview />
      <ConcernQueue />
    </div>
  );
}

function PulseOverview() {
  const { t } = useT();
  const { data, isLoading, isError, refetch } = usePulseInsights();
  const { data: concerns } = useConcerns("all", 1);
  const open = (concerns?.counts.new ?? 0) + (concerns?.counts.in_review ?? 0);

  const recent = data?.recent;
  const topTags = Object.entries(recent?.tags ?? {}).slice(0, 6);
  const maxTag = Math.max(1, ...topTags.map(([, n]) => n));

  return (
    <div className="space-y-6">
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard
          label={t("voice.stat_average", "Pulse, last 4 weeks")}
          value={recent?.average != null ? `${recent.average} / 5` : "—"}
          hint={
            recent && recent.average == null
              ? t("voice.not_enough", "Shown from :n answers", {
                  n: data?.min_group ?? 5,
                })
              : undefined
          }
          icon={Gauge}
          tone="primary"
          loading={isLoading}
        />
        <StatCard
          label={t("voice.stat_responses", "Answers, last 4 weeks")}
          value={recent?.responses ?? 0}
          icon={Users}
          tone="info"
          loading={isLoading}
        />
        <StatCard
          label={t("voice.stat_open", "Open concerns")}
          value={open}
          icon={MessageSquareWarning}
          tone={open > 0 ? "warning" : "success"}
        />
        <StatCard
          label={t("voice.stat_resolved", "Resolved concerns")}
          value={
            (concerns?.counts.resolved ?? 0) + (concerns?.counts.closed ?? 0)
          }
          icon={CheckCircle2}
          tone="success"
        />
      </div>

      <div className="grid gap-6 xl:grid-cols-3">
        <ChartCard
          className="xl:col-span-2"
          title={t("voice.trend", "Weekly pulse")}
          description={t(
            "voice.trend_desc",
            "Average answer per week, 1 (very hard) to 5 (great). Weeks with fewer than 5 answers are not shown.",
          )}
        >
          {isLoading ? (
            <Skeleton className="h-64" />
          ) : isError ? (
            <ErrorState compact onRetry={() => refetch()} />
          ) : (data?.trend ?? []).every((w) => w.average === null) ? (
            <p className="flex h-64 items-center justify-center text-center text-sm text-muted-foreground">
              {t(
                "voice.trend_empty",
                "The chart appears once a week has 5 or more answers.",
              )}
            </p>
          ) : (
            <ResponsiveContainer width="100%" height={260}>
              <AreaChart
                data={data?.trend ?? []}
                margin={{ top: 8, right: 8, left: 0, bottom: 0 }}
              >
                <defs>
                  <linearGradient id="voice-pulse" x1="0" y1="0" x2="0" y2="1">
                    <stop
                      offset="0%"
                      stopColor={seriesColor(0)}
                      stopOpacity={0.3}
                    />
                    <stop
                      offset="100%"
                      stopColor={seriesColor(0)}
                      stopOpacity={0}
                    />
                  </linearGradient>
                </defs>
                <CartesianGrid {...gridProps} />
                <XAxis
                  dataKey="week"
                  {...axisProps}
                  tickFormatter={(w: string) => w.slice(5)}
                />
                <YAxis
                  {...axisProps}
                  domain={[1, 5]}
                  ticks={[1, 2, 3, 4, 5]}
                  width={32}
                />
                <Tooltip {...lineTooltipProps} />
                <Area
                  type="monotone"
                  dataKey="average"
                  name={t("voice.average", "Average")}
                  stroke={seriesColor(0)}
                  fill="url(#voice-pulse)"
                  strokeWidth={2}
                  connectNulls={false}
                />
              </AreaChart>
            </ResponsiveContainer>
          )}
        </ChartCard>

        <ChartCard title={t("voice.topics", "What it is about")}>
          {topTags.length === 0 ? (
            <p className="text-sm text-muted-foreground">
              {t(
                "voice.topics_empty",
                "Topics appear once enough people have answered.",
              )}
            </p>
          ) : (
            <ul className="space-y-3">
              {topTags.map(([tag, n]) => (
                <li key={tag}>
                  <div className="flex justify-between text-sm">
                    <span>{t(`voice.tag.${tag}`, tag)}</span>
                    <span className="tabular-nums text-muted-foreground">
                      {n}
                    </span>
                  </div>
                  <div className="mt-1 h-2 rounded-full bg-muted">
                    <div
                      className="h-2 rounded-full bg-primary"
                      style={{ width: `${(n / maxTag) * 100}%` }}
                    />
                  </div>
                </li>
              ))}
            </ul>
          )}
        </ChartCard>
      </div>

      {(recent?.departments.length ?? 0) > 0 && (
        <ChartCard
          title={t("voice.by_department", "By department (groups of 5+)")}
        >
          <ul className="divide-y">
            {recent?.departments.map((d) => (
              <li
                key={d.department ?? "none"}
                className="flex items-center justify-between py-2 text-sm"
              >
                <span>
                  {d.department ?? t("voice.no_department", "No department")}
                </span>
                <span className="tabular-nums text-muted-foreground">
                  {d.average} / 5 ·{" "}
                  {t("voice.n_answers", ":n answers", { n: d.responses })}
                </span>
              </li>
            ))}
          </ul>
        </ChartCard>
      )}

      {(recent?.comments.length ?? 0) > 0 && (
        <ChartCard
          title={t("voice.comments", "What people said")}
          description={t(
            "voice.comments_desc",
            "Anonymous comments from the last 4 weeks.",
          )}
        >
          <ul className="space-y-3">
            {recent?.comments.map((c, i) => (
              <li
                key={`${c.week}-${i}`}
                className="rounded-lg border p-3 text-sm"
              >
                <p className="whitespace-pre-line">{c.comment}</p>
                <p className="mt-1 text-xs text-muted-foreground">
                  {c.week} · {c.score}/5
                  {c.tags.length > 0 &&
                    ` · ${c.tags.map((tag) => t(`voice.tag.${tag}`, tag)).join(", ")}`}
                </p>
              </li>
            ))}
          </ul>
        </ChartCard>
      )}
    </div>
  );
}

function ConcernQueue() {
  const { t } = useT();
  const { timeAgo } = useDateFormatters();
  const queryFilters = useQueryFilters(
    { concern: "open" },
    { concern: ["open", ...CONCERN_STATUSES] },
  );
  const status = queryFilters.values.concern;
  const [page, setPage] = useState(1);
  const [open, setOpen] = useState<string | null>(null);
  const filter = status === "open" ? "all" : status;
  const { data, isLoading, isError, refetch } = useConcerns(filter, page);
  const rows = (data?.data ?? []).filter(
    (c) => status !== "open" || c.status === "new" || c.status === "in_review",
  );

  const filters = [
    { value: "open", label: t("voice.filter_open", "Open") },
    ...CONCERN_STATUSES.map((s) => ({
      value: s,
      label: t(`voice.status.${s}`, s),
    })),
  ];

  return (
    <Card>
      <CardHeader className="flex-row items-center justify-between gap-3 space-y-0">
        <CardTitle className="flex items-center gap-2 text-base">
          <Inbox className="h-4 w-4 text-primary" aria-hidden="true" />
          {t("voice.concerns", "Concerns")}
        </CardTitle>
        <div
          role="radiogroup"
          className="inline-flex rounded-lg border bg-muted/40 p-0.5"
        >
          {filters.map((f) => (
            <button
              key={f.value}
              type="button"
              role="radio"
              aria-checked={status === f.value}
              onClick={() => {
                queryFilters.set({ concern: f.value });
                setPage(1);
              }}
              className={cn(
                "rounded-md px-2.5 py-1 text-xs font-medium focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
                status === f.value
                  ? "bg-background text-foreground shadow-xs"
                  : "text-muted-foreground hover:text-foreground",
              )}
            >
              {f.label}
            </button>
          ))}
        </div>
      </CardHeader>
      <CardContent>
        {isLoading ? (
          <Skeleton className="h-32" />
        ) : isError ? (
          <ErrorState onRetry={() => refetch()} />
        ) : rows.length === 0 ? (
          <p className="py-6 text-center text-sm text-muted-foreground">
            {t("voice.no_concerns", "No concerns here.")}
          </p>
        ) : (
          <ul className="divide-y">
            {rows.map((c) => (
              <li key={c.public_id}>
                <button
                  type="button"
                  onClick={() => setOpen(c.public_id)}
                  className="flex w-full items-start justify-between gap-3 py-3 text-left hover:bg-muted/40"
                >
                  <span className="min-w-0">
                    <span className="block truncate text-sm font-medium">
                      {c.subject}
                    </span>
                    <span className="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground">
                      {t(`voice.category.${c.category}`, c.category)}
                      <span>·</span>
                      {c.anonymous ? (
                        <span className="inline-flex items-center gap-1">
                          <EyeOff className="h-3 w-3" aria-hidden="true" />
                          {t("voice.anonymous_reporter", "Anonymous")}
                        </span>
                      ) : (
                        c.reporter_name
                      )}
                      {c.created_at && (
                        <>
                          <span>·</span>
                          {timeAgo(c.created_at)}
                        </>
                      )}
                    </span>
                  </span>
                  <ConcernStatusBadge status={c.status} />
                </button>
              </li>
            ))}
          </ul>
        )}
        {status !== "open" && (
          <PaginationControls meta={data?.meta} onPageChange={setPage} />
        )}
      </CardContent>
      <HrConcernDialog publicId={open} onClose={() => setOpen(null)} />
    </Card>
  );
}

function HrConcernDialog({
  publicId,
  onClose,
}: {
  publicId: string | null;
  onClose: () => void;
}) {
  const { t } = useT();
  const { data } = useConcern(publicId);
  const reply = useConcernMessage();
  const update = useUpdateConcern();
  const [status, setStatus] = useState<ConcernStatus | "">("");
  const [resolution, setResolution] = useState("");

  const nextStatus = (status || data?.status) as ConcernStatus | undefined;

  function save() {
    if (!data || !nextStatus) return;
    update.mutate(
      {
        publicId: data.public_id,
        status: nextStatus,
        resolution: resolution.trim() || null,
      },
      {
        onSuccess: () => {
          toast.success(t("voice.updated", "Concern updated"));
          setStatus("");
          setResolution("");
        },
        onError: (err) =>
          toast.error(
            apiErrorMessage(
              err,
              t("voice.resolution_required", "Add how it was resolved"),
            ),
          ),
      },
    );
  }

  return (
    <Dialog open={publicId !== null} onOpenChange={(o) => !o && onClose()}>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
        {!data ? (
          <>
            <DialogTitle className="sr-only">…</DialogTitle>
            <Skeleton className="h-48" />
          </>
        ) : (
          <div className="space-y-6">
            <ConcernThread
              concern={data}
              viewer="hr"
              sending={reply.isPending}
              onReply={(body) =>
                reply.mutateAsync({ publicId: data.public_id, body })
              }
            />
            <div className="space-y-3 rounded-lg border p-4">
              <div className="grid gap-3 sm:grid-cols-[12rem_1fr] sm:items-end">
                <div>
                  <Label htmlFor="concern-status">
                    {t("voice.status_label", "Status")}
                  </Label>
                  <Select
                    value={nextStatus}
                    onValueChange={(v) => setStatus(v as ConcernStatus)}
                  >
                    <SelectTrigger id="concern-status" className="mt-1">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {CONCERN_STATUSES.map((s) => (
                        <SelectItem key={s} value={s}>
                          {t(`voice.status.${s}`, s)}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                {(nextStatus === "resolved" || nextStatus === "closed") && (
                  <div>
                    <Label htmlFor="concern-resolution">
                      {t(
                        "voice.resolution_label",
                        "How it was resolved (the reporter sees this)",
                      )}
                    </Label>
                    <Textarea
                      id="concern-resolution"
                      value={resolution}
                      onChange={(e) => setResolution(e.target.value)}
                      rows={2}
                      maxLength={3000}
                      className="mt-1"
                    />
                  </div>
                )}
              </div>
              <div className="flex justify-end">
                <Button size="sm" onClick={save} disabled={update.isPending}>
                  {update.isPending && (
                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                  )}
                  {t("voice.save_status", "Save")}
                </Button>
              </div>
            </div>
          </div>
        )}
      </DialogContent>
    </Dialog>
  );
}
