import { useT } from "@/lib/i18n/useT";

type Translate = (key: string, fallback?: string) => string;

/** The notification types a person sets channels for (App\Enums\NotificationType). */
export const NOTIFICATION_TYPES = [
  "leave_requested",
  "leave_approved",
  "leave_rejected",
  "leave_changed",
  "attendance_correction",
  "overtime_request",
  "attendance_anomaly",
  "payslip_available",
  "payroll_processed",
  "announcement",
  "approval_reminder",
  "expiry_reminder",
  "profile_update",
  "employee_voice",
  "letter",
] as const;
export type NotificationTypeValue = (typeof NOTIFICATION_TYPES)[number];

/** One set of words for every screen: the `notification_prefs_page.type_*` keys. */
export function notificationTypeLabel(t: Translate, value: string): string {
  switch (value) {
    case "leave_requested":
      return t(
        "notification_prefs_page.type_leave_requested",
        "Leave requested",
      );
    case "leave_approved":
      return t("notification_prefs_page.type_leave_approved", "Leave approved");
    case "leave_rejected":
      return t("notification_prefs_page.type_leave_rejected", "Leave rejected");
    case "leave_changed":
      return t("notification_prefs_page.type_leave_changed", "Leave changed");
    case "attendance_correction":
      return t(
        "notification_prefs_page.type_correction_request",
        "Correction Request",
      );
    case "overtime_request":
      return t(
        "notification_prefs_page.type_overtime_request",
        "Overtime requests",
      );
    case "attendance_anomaly":
      return t(
        "notification_prefs_page.type_attendance_anomaly",
        "Attendance Anomaly",
      );
    case "payslip_available":
      return t(
        "notification_prefs_page.type_payslip_available",
        "Payslip available",
      );
    case "payroll_processed":
      return t(
        "notification_prefs_page.type_payroll_processed",
        "Payroll Processed",
      );
    case "announcement":
      return t("notification_prefs_page.type_announcement", "Announcements");
    case "approval_reminder":
      return t(
        "notification_prefs_page.type_approval_reminder",
        "Approval Reminder",
      );
    case "expiry_reminder":
      return t(
        "notification_prefs_page.type_expiry_reminder",
        "Expiry Reminder",
      );
    case "profile_update":
      return t("approvals.type_profile_update", "Profile change");
    case "employee_voice":
      return t(
        "notification_prefs_page.type_employee_voice",
        "Voice: concerns and recognition",
      );
    case "letter":
      return t("notification_prefs_page.type_letter", "Letters");
    default:
      return value;
  }
}

/** The line under a type's name. A profile change has none. */
export function notificationTypeDescription(
  t: Translate,
  value: string,
): string {
  switch (value) {
    case "leave_requested":
      return t(
        "notification_prefs_page.desc_leave_requested",
        "When a team member submits a leave request",
      );
    case "leave_approved":
      return t(
        "notification_prefs_page.desc_leave_approved",
        "When your leave request is approved",
      );
    case "leave_rejected":
      return t(
        "notification_prefs_page.desc_leave_rejected",
        "When your leave request is rejected",
      );
    case "leave_changed":
      return t(
        "notification_prefs_page.desc_leave_changed",
        "When HR cancels or shortens your approved leave",
      );
    case "attendance_correction":
      return t(
        "notification_prefs_page.desc_correction_request",
        "When a correction needs your approval",
      );
    case "overtime_request":
      return t(
        "notification_prefs_page.desc_overtime_request",
        "When someone asks to work overtime, and when your request is decided",
      );
    case "attendance_anomaly":
      return t(
        "notification_prefs_page.desc_attendance_anomaly",
        "Late arrivals, missing punches",
      );
    case "payslip_available":
      return t(
        "notification_prefs_page.desc_payslip_available",
        "When your payslip is generated",
      );
    case "payroll_processed":
      return t(
        "notification_prefs_page.desc_payroll_processed",
        "When payroll has been run for the period",
      );
    case "announcement":
      return t(
        "notification_prefs_page.desc_announcement",
        "Organization-wide announcements",
      );
    case "approval_reminder":
      return t(
        "notification_prefs_page.desc_approval_reminder",
        "Reminders for items pending your approval",
      );
    case "expiry_reminder":
      return t(
        "notification_prefs_page.desc_expiry_reminder",
        "Contracts, probation periods and documents that end soon",
      );
    case "employee_voice":
      return t(
        "notification_prefs_page.desc_employee_voice",
        "Replies to your concerns, and colleagues who thank you",
      );
    case "letter":
      return t(
        "notification_prefs_page.desc_letter",
        "When an employment letter is requested, and when yours is issued or declined",
      );
    default:
      return "";
  }
}

export function useNotificationTypeLabel() {
  const { t } = useT();
  return (value: string) => notificationTypeLabel(t, value);
}

export function useNotificationTypeDescription() {
  const { t } = useT();
  return (value: string) => notificationTypeDescription(t, value);
}
