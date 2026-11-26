import { useT } from "@/lib/i18n/useT";

/**
 * What a person has been used for, as plain words, for every screen that keeps
 * someone from being archived: the undo of an import list and archiving a
 * selection of people. The codes are `App\Services\Migration\AdditionUse`'s,
 * plus `self` (the record is the reader's own login's). The keys keep the
 * `migration_undo.` prefix they were first written under.
 *
 * A code this screen does not know reads as "used", never as a raw code.
 * `extra` adds a screen's own reasons, such as the undo's "not certain".
 */
export function useArchiveReasonText(
  extra: Record<string, string> = {},
): (reasons: string[]) => string {
  const { t } = useT();

  const labels: Record<string, string> = {
    attendance: t("migration_undo.reason_attendance", "Has attendance records"),
    signed_in: t("migration_undo.reason_signed_in", "Has signed in"),
    payroll: t("migration_undo.reason_payroll", "Has been in a payroll"),
    leave: t("migration_undo.reason_leave", "Has leave records"),
    overtime: t("migration_undo.reason_overtime", "Has overtime records"),
    documents: t("migration_undo.reason_documents", "Has documents"),
    hr_records: t("migration_undo.reason_hr_records", "Has HR records"),
    in_charge: t("migration_undo.reason_in_charge", "Is in charge of others"),
    assigned: t("migration_undo.reason_assigned", "Has been assigned work"),
    self: t("employees.archive.reason_self", "This is your own record"),
    ...extra,
  };
  const unknown = t("migration_undo.reason_unknown", "Has been used");

  return (reasons) =>
    Array.from(new Set(reasons.map((r) => labels[r] ?? unknown))).join(", ");
}
