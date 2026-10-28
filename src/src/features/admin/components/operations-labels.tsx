"use client";

import { Badge } from "@/components/ui/badge";
import type { useT } from "@/lib/i18n/useT";
import type {
  OperationsArea,
  OperationsState,
} from "@/features/admin/operations";

type Translate = ReturnType<typeof useT>["t"];

const STATE_VARIANT: Record<
  OperationsState,
  "success" | "warning" | "destructive" | "neutral"
> = {
  ok: "success",
  warning: "warning",
  critical: "destructive",
  unknown: "neutral",
};

export function stateLabel(t: Translate, state: OperationsState): string {
  switch (state) {
    case "ok":
      return t("operations_page.state.ok", "Healthy");
    case "warning":
      return t("operations_page.state.warning", "Needs a look");
    case "critical":
      return t("operations_page.state.critical", "Action needed");
    case "unknown":
      return t("operations_page.state.unknown", "Unknown");
  }
}

export function StateBadge({
  state,
  t,
}: {
  state: OperationsState;
  t: Translate;
}) {
  return <Badge variant={STATE_VARIANT[state]}>{stateLabel(t, state)}</Badge>;
}

export function areaLabel(t: Translate, area: OperationsArea): string {
  switch (area) {
    case "background":
      return t("operations_page.area.background", "Background work");
    case "delivery":
      return t("operations_page.area.delivery", "Email delivery");
    case "release":
      return t("operations_page.area.release", "Release");
    case "failed_jobs":
      return t("operations_page.area.failed_jobs", "Failed jobs");
    case "services":
      return t("operations_page.area.services", "Services");
  }
}

/**
 * The server sends reason codes, not sentences, so each one is translated
 * here. A code this build does not know is shown as itself rather than
 * hidden: a new reason from a newer API must still be visible.
 */
export function reasonLabel(t: Translate, code: string): string {
  switch (code) {
    case "scheduler_never_run":
      return t(
        "operations_page.reason.scheduler_never_run",
        "The scheduler has never run. Check that the external cron caller is set up with CRON_TOKEN.",
      );
    case "scheduler_stale":
      return t(
        "operations_page.reason.scheduler_stale",
        "The scheduler has not run recently, so scheduled work is not happening.",
      );
    case "external_caller_never_seen":
      return t(
        "operations_page.reason.external_caller_never_seen",
        "No call from the external cron caller has been recorded yet.",
      );
    case "external_caller_stale":
      return t(
        "operations_page.reason.external_caller_stale",
        "The external cron caller has gone quiet. Page views keep work moving during the day, but nothing runs overnight, including the backup.",
      );
    case "queue_waiting":
      return t(
        "operations_page.reason.queue_waiting",
        "A queue has a job waiting longer than expected.",
      );
    case "failed_jobs_present":
      return t(
        "operations_page.reason.failed_jobs_present",
        "Some jobs failed. Retry or dismiss them on the console home.",
      );
    case "failed_jobs_unreadable":
      return t(
        "operations_page.reason.failed_jobs_unreadable",
        "The failed jobs table could not be read.",
      );
    case "mail_not_sent_by_this_mailer":
      return t(
        "operations_page.reason.mail_not_sent_by_this_mailer",
        "This mailer does not send email, so delivery cannot be measured.",
      );
    case "mail_no_record":
      return t(
        "operations_page.reason.mail_no_record",
        "No email has been sent or has failed since this was last recorded.",
      );
    case "mail_failing":
      return t(
        "operations_page.reason.mail_failing",
        "The last email failed after the last one that was sent. Check the mail server and its certificate.",
      );
    case "pending_migrations":
      return t(
        "operations_page.reason.pending_migrations",
        "Database migrations are waiting. Run the release steps.",
      );
    case "maintenance_routes_open":
      return t(
        "operations_page.reason.maintenance_routes_open",
        "The maintenance routes are open. Empty MAINTENANCE_TOKEN.",
      );
    case "config_cache_stale":
      return t(
        "operations_page.reason.config_cache_stale",
        "The configuration cache is older than this release. Run optimize.",
      );
    case "route_cache_stale":
      return t(
        "operations_page.reason.route_cache_stale",
        "The route cache is older than this release. Run optimize, or new pages will answer 404.",
      );
    case "debug_on":
      return t(
        "operations_page.reason.debug_on",
        "Debug mode is on in production.",
      );
    case "queue_sync":
      return t(
        "operations_page.reason.queue_sync",
        "The queue runs inside each request in production.",
      );
    case "config_not_cached":
      return t(
        "operations_page.reason.config_not_cached",
        "The configuration is not cached. Run optimize.",
      );
    case "routes_not_cached":
      return t(
        "operations_page.reason.routes_not_cached",
        "The routes are not cached. Run optimize.",
      );
    case "cron_token_missing":
      return t(
        "operations_page.reason.cron_token_missing",
        "CRON_TOKEN is not set, so the external caller cannot run any work.",
      );
    case "migrations_unreadable":
      return t(
        "operations_page.reason.migrations_unreadable",
        "The list of migrations could not be read.",
      );
    case "no_release_stamp":
      return t(
        "operations_page.reason.no_release_stamp",
        "This install has no release stamp, so its version is unknown.",
      );
    case "service_unhealthy":
      return t(
        "operations_page.reason.service_unhealthy",
        "A service check failed.",
      );
    default:
      return code;
  }
}
