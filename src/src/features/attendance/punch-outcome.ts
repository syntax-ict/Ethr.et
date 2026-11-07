/**
 * What a punch came to, in a sentence: checked in on time or late, checked out
 * or early, or "already checked in", with the method that did it and when.
 *
 * Several ways of punching (the phone, the kiosk, the web, a QR code) share one
 * record a day. The API answers every punch with `outcome`, and every screen
 * says it with this one function, so a person told "already checked in at
 * 08:25 (Mobile)" at the kiosk reads the same words on their phone.
 */
export interface PunchOutcomeInfo {
  code: string;
  minutes: number;
  by: string | null;
  at: string | null;
}

type Translate = (
  key: string,
  fallback?: string,
  replacements?: Record<string, string | number>,
) => string;

export type OutcomeTone = "success" | "warning" | "info";

export interface PunchOutcomeText {
  code: string;
  tone: OutcomeTone;
  /** True when the punch added nothing: the day already had it. */
  already: boolean;
  title: string;
  hint: string | null;
}

/** The label of the method behind a punch (`mobile`, `kiosk`, `web` ...). */
export function methodLabel(by: string | null, t: Translate): string {
  switch (by) {
    case "mobile":
      return t("attendance.source_mobile", "Mobile");
    case "kiosk":
      return t("attendance.source_kiosk", "Kiosk");
    case "web":
      return t("attendance.source_web", "Web");
    case "qr":
      return t("attendance.source_qr", "QR");
    case "biometric":
      return t("attendance.source_biometric", "Biometric");
    case "manual":
      return t("attendance.source_manual", "Manual");
    case "csv":
      return t("attendance.source_csv", "CSV Import");
    case "offline_mobile":
      return t("attendance.source_offline", "Offline");
    default:
      return "";
  }
}

/**
 * The sentence for an outcome, or null when the server sent none (a break,
 * an older server). `formatTime` is the caller's own clock, so the time is
 * written the way the reader reads it.
 */
export function punchOutcomeText(
  outcome: PunchOutcomeInfo | null | undefined,
  t: Translate,
  formatTime: (iso: string) => string,
): PunchOutcomeText | null {
  if (!outcome) return null;

  const when = outcome.at ? formatTime(outcome.at) : "";
  const method = methodLabel(outcome.by, t);
  const hint = t(
    "attendance.outcome.one_record_hint",
    "Your day has one record, whichever way you punch.",
  );

  switch (outcome.code) {
    case "checked_in":
      return {
        code: outcome.code,
        tone: "success",
        already: false,
        title: t("attendance.checked_in", "Checked in successfully"),
        hint: null,
      };
    case "checked_in_late":
      return {
        code: outcome.code,
        tone: "warning",
        already: false,
        title: t(
          "attendance.outcome.checked_in_late",
          "Checked in, :minutes min late",
          {
            minutes: outcome.minutes,
          },
        ),
        hint: null,
      };
    case "checked_in_again":
      return {
        code: outcome.code,
        tone: "info",
        already: false,
        title: t(
          "attendance.outcome.checked_in_again",
          "Checked in again, kept as a second session",
        ),
        hint: null,
      };
    case "already_checked_in":
      return {
        code: outcome.code,
        tone: "info",
        already: true,
        title: t(
          "attendance.outcome.already_checked_in",
          "Already checked in at :time (:method)",
          { time: when, method },
        ),
        hint,
      };
    case "checked_out":
      return {
        code: outcome.code,
        tone: "success",
        already: false,
        title: t("attendance.checked_out", "Checked out successfully"),
        hint: null,
      };
    case "checked_out_early":
      return {
        code: outcome.code,
        tone: "warning",
        already: false,
        title: t(
          "attendance.outcome.checked_out_early",
          "Checked out, :minutes min early",
          {
            minutes: outcome.minutes,
          },
        ),
        hint: null,
      };
    case "already_checked_out":
      return {
        code: outcome.code,
        tone: "info",
        already: true,
        title: t(
          "attendance.outcome.already_checked_out",
          "Already checked out at :time (:method)",
          { time: when, method },
        ),
        hint,
      };
    default:
      return null;
  }
}
