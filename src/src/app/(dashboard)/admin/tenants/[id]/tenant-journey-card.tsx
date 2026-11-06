"use client";

import {
  Activity,
  CalendarClock,
  Cpu,
  Plane,
  Users,
  Wallet,
} from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import { Stepper, type Step } from "@/components/shared/stepper";
import { StatCard } from "@/components/dashboard/stat-card";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { useT } from "@/lib/i18n/useT";
import {
  JOURNEY_STAGES,
  isJourneyStage,
  journeyHealth,
  useTenantJourney,
  type JourneyStage,
  type TenantJourney,
} from "@/features/admin/journey";
import { StateBadge } from "@/features/admin/components/operations-labels";
import {
  eventLabel,
  journeyReasonLabel,
  milestoneLabel,
  stageHint,
  stageLabel,
} from "@/features/admin/components/journey-labels";

/** The milestone that dates each stage; the last stage is dated by `stage_reached_at`. */
const STAGE_MILESTONE: Record<JourneyStage, string | null> = {
  registered: "registered",
  configured: "configured",
  people_added: "people_added",
  team_activated: "team_activated",
  first_use: "first_use",
  successfully_using: null,
};

const MILESTONE_ORDER = [
  "registered",
  "configured",
  "people_added",
  "team_activated",
  "first_use",
  "first_payroll",
  "went_live",
  "converted",
];

/**
 * Where one organisation is between sign-up and using ETHR, and why
 * (docs/operations/TENANT-JOURNEY.md): the stage, what needs attention, the
 * counts behind it, the milestones with their dates, readiness, and the
 * lifecycle events. Counts and dates only; nobody's own record is shown here.
 */
export function TenantJourneyCard({ publicId }: { publicId: string }) {
  const { t } = useT();
  const query = useTenantJourney(publicId);

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">
          {t("admin_tenant_journey.title", "Journey")}
        </CardTitle>
      </CardHeader>
      <CardContent>
        <QueryBoundary query={query}>
          {(journey) => <JourneyBody journey={journey} />}
        </QueryBoundary>
      </CardContent>
    </Card>
  );
}

function JourneyBody({ journey }: { journey: TenantJourney }) {
  const { t } = useT();
  const { formatDate, formatDateTime } = useDateFormatters();

  const stage = isJourneyStage(journey.stage) ? journey.stage : "registered";
  const stageIndex = JOURNEY_STAGES.indexOf(stage);
  const finished = stage === "successfully_using";
  const health = journeyHealth(journey.health_state);
  const m = journey.metrics;

  const steps: Step[] = JOURNEY_STAGES.map((key, i) => {
    const milestone = STAGE_MILESTONE[key];
    const reachedAt =
      key === "successfully_using"
        ? journey.stage_reached_at
        : milestone
          ? (journey.milestones[milestone] ?? null)
          : null;

    return {
      key,
      label: stageLabel(t, key),
      state:
        i < stageIndex || finished
          ? "complete"
          : i === stageIndex
            ? "current"
            : "upcoming",
      description:
        i <= stageIndex && reachedAt
          ? t("admin_tenant_journey.reached_on", "Reached :date", {
              date: formatDate(reachedAt),
            })
          : stageHint(t, key),
    };
  });

  const milestones = MILESTONE_ORDER.filter(
    (key) =>
      key !== "first_payroll" || m.payroll_enabled || journey.milestones[key],
  );

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center gap-3">
        <StateBadge state={health} t={t} />
        <span className="text-sm font-medium text-foreground">
          {stageLabel(t, stage)}
        </span>
        <span className="ml-auto text-xs text-muted-foreground">
          {t("admin_tenant_journey.updated", "Updated :time", {
            time: formatDateTime(journey.computed_at),
          })}
        </span>
      </div>

      <Stepper
        steps={steps}
        label={t("admin_tenant_journey.stepper_label", "Journey stages")}
      />

      <section aria-labelledby="journey-attention" className="space-y-2">
        <h3
          id="journey-attention"
          className="text-sm font-semibold text-foreground"
        >
          {t("admin_tenant_journey.attention_title", "What needs attention")}
        </h3>
        {journey.reasons.length === 0 ? (
          <p className="text-sm text-muted-foreground">
            {t(
              "admin_tenant_journey.attention_none",
              "Nothing needs attention.",
            )}
          </p>
        ) : (
          <ul className="list-disc space-y-1 pl-5 text-sm text-foreground">
            {journey.reasons.map((code) => (
              <li key={code}>{journeyReasonLabel(t, code)}</li>
            ))}
          </ul>
        )}
      </section>

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <StatCard
          icon={Users}
          label={t(
            "admin_tenant_journey.metric.employees",
            "Current employees",
          )}
          value={String(m.employees)}
        />
        <StatCard
          icon={Users}
          tone="info"
          label={t(
            "admin_tenant_journey.metric.signed_in",
            "Teammates signed in",
          )}
          value={`${m.activated_users} / ${m.team_users}`}
          hint={t(
            "admin_tenant_journey.metric.signed_in_hint",
            "Signed in during the last 30 days: :count",
            {
              count: m.users_logged_in_30d,
            },
          )}
        />
        <StatCard
          icon={Activity}
          tone="success"
          label={t(
            "admin_tenant_journey.metric.punches",
            "Punches, last 30 days",
          )}
          value={String(m.punches_30d)}
          hint={
            m.punch_sources_30d.length === 0
              ? t(
                  "admin_tenant_journey.metric.punches_none",
                  "No punches recorded",
                )
              : m.punch_sources_30d
                  .map((s) => `${s.source} ${s.count}`)
                  .join(", ")
          }
        />
        <StatCard
          icon={Plane}
          tone="brand"
          label={t(
            "admin_tenant_journey.metric.leave",
            "Leave requests, last 30 days",
          )}
          value={String(m.leave_requests_30d)}
        />
        {m.payroll_enabled && (
          <StatCard
            icon={Wallet}
            tone="primary"
            label={t(
              "admin_tenant_journey.metric.payroll",
              "Completed payroll runs",
            )}
            value={String(m.payroll_runs)}
          />
        )}
        <StatCard
          icon={Cpu}
          tone={m.devices_silent > 0 ? "warning" : "neutral"}
          label={t("admin_tenant_journey.metric.devices", "Devices")}
          value={String(m.devices)}
          hint={
            m.devices_silent > 0
              ? t(
                  "admin_tenant_journey.metric.devices_silent",
                  "Not syncing: :count",
                  {
                    count: m.devices_silent,
                  },
                )
              : undefined
          }
        />
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        <section aria-labelledby="journey-milestones" className="space-y-2">
          <h3
            id="journey-milestones"
            className="text-sm font-semibold text-foreground"
          >
            {t("admin_tenant_journey.milestones_title", "Milestones")}
          </h3>
          <ul className="divide-y rounded-lg border">
            {milestones.map((key) => {
              const at = journey.milestones[key];
              return (
                <li
                  key={key}
                  className="flex items-center justify-between gap-3 px-3 py-2 text-sm"
                >
                  <span className="text-foreground">
                    {milestoneLabel(t, key)}
                  </span>
                  <span className="tabular-nums text-muted-foreground">
                    {at
                      ? formatDate(at)
                      : t("admin_tenant_journey.not_yet", "Not yet")}
                  </span>
                </li>
              );
            })}
          </ul>
          <p className="text-xs text-muted-foreground">
            {journey.last_activity_at
              ? t("admin_tenant_journey.last_activity", "Last activity :time", {
                  time: formatDateTime(journey.last_activity_at),
                })
              : t(
                  "admin_tenant_journey.no_activity",
                  "No activity recorded yet",
                )}
          </p>
        </section>

        <section aria-labelledby="journey-readiness" className="space-y-2">
          <h3
            id="journey-readiness"
            className="text-sm font-semibold text-foreground"
          >
            {t("admin_tenant_journey.readiness_title", "Setup readiness")}
          </h3>
          <p className="text-sm text-foreground">
            {t("admin_tenant_journey.readiness_score", ":score% ready", {
              score: journey.readiness.score,
            })}
          </p>
          {journey.readiness.gaps.length === 0 ? (
            <p className="text-sm text-muted-foreground">
              {t("admin_tenant_journey.readiness_none", "Every check passes.")}
            </p>
          ) : (
            <ul className="list-disc space-y-1 pl-5 text-sm text-muted-foreground">
              {journey.readiness.gaps.map((gap) => (
                <li key={gap.id}>{t(`setup2.check_${gap.id}`, gap.label)}</li>
              ))}
            </ul>
          )}
        </section>
      </div>

      <section aria-labelledby="journey-events" className="space-y-2">
        <h3
          id="journey-events"
          className="flex items-center gap-2 text-sm font-semibold text-foreground"
        >
          <CalendarClock
            className="h-4 w-4 text-muted-foreground"
            aria-hidden="true"
          />
          {t("admin_tenant_journey.events_title", "Lifecycle events")}
        </h3>
        {journey.events.length === 0 ? (
          <p className="text-sm text-muted-foreground">
            {t(
              "admin_tenant_journey.events_none",
              "No lifecycle events recorded.",
            )}
          </p>
        ) : (
          <ul className="divide-y rounded-lg border">
            {journey.events.map((event, i) => (
              <li
                key={`${event.action}-${event.created_at}-${i}`}
                className="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-sm"
              >
                <span className="text-foreground">
                  {eventLabel(t, event.action)}
                  {event.by_platform && (
                    <span className="ml-2 text-xs text-muted-foreground">
                      {t("admin_tenant_journey.by_platform", "by ETHR")}
                    </span>
                  )}
                </span>
                <span className="tabular-nums text-muted-foreground">
                  {formatDateTime(event.created_at)}
                </span>
              </li>
            ))}
          </ul>
        )}
      </section>
    </div>
  );
}
