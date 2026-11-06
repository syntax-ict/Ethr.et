"use client";

import type { useT } from "@/lib/i18n/useT";
import type { JourneyStage } from "@/features/admin/journey";

type Translate = ReturnType<typeof useT>["t"];

export function stageLabel(t: Translate, stage: JourneyStage): string {
  switch (stage) {
    case "registered":
      return t("admin_tenant_journey.stage.registered", "Signed up");
    case "configured":
      return t("admin_tenant_journey.stage.configured", "Setup applied");
    case "people_added":
      return t("admin_tenant_journey.stage.people_added", "People added");
    case "team_activated":
      return t("admin_tenant_journey.stage.team_activated", "Team signed in");
    case "first_use":
      return t("admin_tenant_journey.stage.first_use", "First use");
    case "successfully_using":
      return t("admin_tenant_journey.stage.successfully_using", "Using ETHR");
  }
}

export function stageHint(t: Translate, stage: JourneyStage): string {
  switch (stage) {
    case "registered":
      return t(
        "admin_tenant_journey.stage_hint.registered",
        "The organisation exists",
      );
    case "configured":
      return t(
        "admin_tenant_journey.stage_hint.configured",
        "Its industry setup was applied",
      );
    case "people_added":
      return t(
        "admin_tenant_journey.stage_hint.people_added",
        "At least one current employee",
      );
    case "team_activated":
      return t(
        "admin_tenant_journey.stage_hint.team_activated",
        "Someone besides the founder has signed in",
      );
    case "first_use":
      return t(
        "admin_tenant_journey.stage_hint.first_use",
        "A punch or a leave request exists",
      );
    case "successfully_using":
      return t(
        "admin_tenant_journey.stage_hint.successfully_using",
        "Recent activity, and a payroll run when the plan has payroll",
      );
  }
}

/**
 * The server sends reason codes, not sentences. A code this build does not
 * know is shown as itself rather than hidden, so a newer API's reason is still
 * visible.
 */
export function journeyReasonLabel(t: Translate, code: string): string {
  switch (code) {
    case "not_operating":
      return t(
        "admin_tenant_journey.reason.not_operating",
        "This organisation is suspended, cancelled or deleted, so its health is not watched.",
      );
    case "trial_expired_not_converted":
      return t(
        "admin_tenant_journey.reason.trial_expired_not_converted",
        "The trial has ended and the organisation has not moved to a paid plan.",
      );
    case "payroll_run_failed":
      return t(
        "admin_tenant_journey.reason.payroll_run_failed",
        "A payroll run failed recently and no run has completed since.",
      );
    case "invoice_past_due":
      return t(
        "admin_tenant_journey.reason.invoice_past_due",
        "An invoice is more than 30 days overdue.",
      );
    case "invoice_overdue":
      return t(
        "admin_tenant_journey.reason.invoice_overdue",
        "An invoice is overdue.",
      );
    case "no_login_14d":
      return t(
        "admin_tenant_journey.reason.no_login_14d",
        "Nobody has signed in for 14 days.",
      );
    case "no_activity_14d_after_first_use":
      return t(
        "admin_tenant_journey.reason.no_activity_14d_after_first_use",
        "It used ETHR before, and nothing has been recorded for 14 days.",
      );
    case "trial_ends_soon_not_ready":
      return t(
        "admin_tenant_journey.reason.trial_ends_soon_not_ready",
        "The trial ends within a week and the organisation is not using ETHR yet.",
      );
    case "invitations_unactivated":
      return t(
        "admin_tenant_journey.reason.invitations_unactivated",
        "People were invited more than a week ago and have not signed in.",
      );
    case "devices_silent":
      return t(
        "admin_tenant_journey.reason.devices_silent",
        "A device has not synced for 3 days.",
      );
    case "stalled_at_stage":
      return t(
        "admin_tenant_journey.reason.stalled_at_stage",
        "It has stayed at this stage for more than 14 days.",
      );
    case "open_support_request":
      return t(
        "admin_tenant_journey.reason.open_support_request",
        "A help request passed to ETHR is still open.",
      );
    default:
      return code;
  }
}

export function milestoneLabel(t: Translate, key: string): string {
  switch (key) {
    case "registered":
      return t("admin_tenant_journey.milestone.registered", "Signed up");
    case "configured":
      return t("admin_tenant_journey.milestone.configured", "Setup applied");
    case "people_added":
      return t(
        "admin_tenant_journey.milestone.people_added",
        "First employee added",
      );
    case "team_activated":
      return t(
        "admin_tenant_journey.milestone.team_activated",
        "First teammate signed in",
      );
    case "first_use":
      return t(
        "admin_tenant_journey.milestone.first_use",
        "First punch or leave request",
      );
    case "first_payroll":
      return t(
        "admin_tenant_journey.milestone.first_payroll",
        "First payroll run completed",
      );
    case "went_live":
      return t("admin_tenant_journey.milestone.went_live", "Marked as live");
    case "converted":
      return t("admin_tenant_journey.milestone.converted", "Chose a plan");
    default:
      return key;
  }
}

export function eventLabel(t: Translate, action: string): string {
  // Audit actions are dotted words, which the literal-key
  // guard reads as dictionary keys, so they are matched in their underscore form.
  switch (action.replace(/\./g, "_")) {
    case "tenant_registered":
      return t("admin_tenant_journey.event.registered", "Signed up");
    case "onboarding_configuration_applied":
      return t(
        "admin_tenant_journey.event.configuration_applied",
        "Setup applied",
      );
    case "onboarding_access_updated":
      return t(
        "admin_tenant_journey.event.access_updated",
        "Sign-in options saved",
      );
    case "onboarding_went_live":
      return t("admin_tenant_journey.event.went_live", "Marked as live");
    case "billing_plan_changed":
      return t("admin_tenant_journey.event.plan_changed", "Plan changed");
    case "billing_invoice_paid":
      return t("admin_tenant_journey.event.invoice_paid", "Invoice paid");
    case "admin_tenant_status_changed":
      return t("admin_tenant_journey.event.status_changed", "Status changed");
    case "admin_tenant_trial_extended":
      return t("admin_tenant_journey.event.trial_extended", "Trial extended");
    case "admin_tenant_deleted":
      return t("admin_tenant_journey.event.deleted", "Closed");
    case "admin_tenant_restored":
      return t("admin_tenant_journey.event.restored", "Restored");
    case "admin_tenant_impersonated":
      return t(
        "admin_tenant_journey.event.impersonated",
        "Opened as the organisation's admin",
      );
    case "admin_tenant_feature_override_set":
      return t(
        "admin_tenant_journey.event.feature_override_set",
        "Feature switch set",
      );
    case "admin_tenant_feature_override_removed":
      return t(
        "admin_tenant_journey.event.feature_override_removed",
        "Feature switch removed",
      );
    case "payroll_processed":
      return t(
        "admin_tenant_journey.event.payroll_processed",
        "Payroll run calculated",
      );
    case "payroll_failed":
      return t(
        "admin_tenant_journey.event.payroll_failed",
        "Payroll run failed",
      );
    case "payroll_approved":
      return t(
        "admin_tenant_journey.event.payroll_approved",
        "Payroll run approved",
      );
    case "payroll_paid":
      return t("admin_tenant_journey.event.payroll_paid", "Payroll run paid");
    default:
      return action;
  }
}
