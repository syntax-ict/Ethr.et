"use client";

import { matchReasonLabel } from "@/features/employees/likeness";
import type { SuggestionIdentity } from "@/features/devices/api";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { useT } from "@/lib/i18n/useT";

/** One person a held badge may be, from the resolver or from the terminal's name. */
export interface Suggested {
  public_id: string;
  name: string;
  /** The resolver's score, 0 to 1, when it named them. */
  score?: number;
  reasons?: string[];
  /** `same` is the terminal's exact name, `similar` a spelling that differs a little. */
  fit?: "same" | "similar";
  identity?: SuggestionIdentity | null;
  /** From the name match, for a server that sent no identity. */
  department?: string | null;
}

/**
 * A suggested person as a reader chooses between them: the name (a button
 * that fills the picker), how it was found, who they are, and what speaks
 * against them. It only offers; choosing does nothing until Assign.
 */
export function SuggestionLine({
  person,
  onChoose,
}: {
  person: Suggested;
  onChoose: () => void;
}) {
  const { t } = useT();
  const { formatDate } = useDateFormatters();
  const identity = person.identity ?? null;
  const department = identity?.department ?? person.department ?? null;

  const facts = identityFacts(identity, department, t, formatDate);

  return (
    <li className="space-y-0.5">
      <div>
        <button
          type="button"
          className="text-primary underline underline-offset-2 hover:no-underline"
          onClick={onChoose}
        >
          {t("devices_page.unmatched.possibly", "Possibly :name", {
            name: person.name,
          })}
        </button>
        {person.score !== undefined && (
          <> · {Math.round(person.score * 100)}%</>
        )}
        {person.reasons && person.reasons.length > 0 && (
          <span className="italic">
            {" "}
            ({person.reasons.map((r) => matchReasonLabel(r, t)).join(", ")})
          </span>
        )}
        {person.fit && (
          <span className="italic">
            {" "}
            (
            {person.fit === "same"
              ? t(
                  "devices_page.unmatched.same_name",
                  "same name on the terminal",
                )
              : t(
                  "devices_page.unmatched.similar_name",
                  "similar name on the terminal",
                )}
            )
          </span>
        )}
      </div>
      {facts.length > 0 && <p>{facts.join(" · ")}</p>}
      {identity?.warnings.map((warning) => (
        <p
          key={warning.code}
          className="text-status-warning"
          data-warning={warning.code}
        >
          {warningText(warning, t, formatDate)}
        </p>
      ))}
    </li>
  );
}

/** The identity line as facts to join: number, department, position, branch, hire date, status. */
export function identityFacts(
  identity: SuggestionIdentity | null,
  department: string | null,
  t: (
    key: string,
    fallback?: string,
    params?: Record<string, string | number>,
  ) => string,
  formatDate: (date: string) => string,
): string[] {
  return [
    identity?.employee_code
      ? t("devices_page.unmatched.identity_number", "No. :code", {
          code: identity.employee_code,
        })
      : null,
    department,
    identity?.position ?? null,
    identity?.branch ?? null,
    identity?.hire_date
      ? t("devices_page.unmatched.identity_hired", "Hired :date", {
          date: formatDate(identity.hire_date),
        })
      : null,
    identity ? statusLabel(identity.status, t) : null,
  ].filter((fact): fact is string => !!fact);
}

function statusLabel(
  status: string,
  t: (key: string, fallback?: string) => string,
): string {
  return status === "archived"
    ? t("devices_page.unmatched.identity_archived", "Archived")
    : t(`status.${status}`, status);
}

export function warningText(
  warning: { code: string; value: string | number | null },
  t: (
    key: string,
    fallback?: string,
    params?: Record<string, string | number>,
  ) => string,
  formatDate: (date: string) => string,
): string {
  switch (warning.code) {
    case "other_badge":
      return t(
        "devices_page.unmatched.warn_other_badge",
        "Already has badge number :badge",
        { badge: String(warning.value ?? "") },
      );
    case "hired_after":
      return t(
        "devices_page.unmatched.warn_hired_after",
        "Hired after this badge's first punch on :date",
        { date: formatDate(String(warning.value ?? "")) },
      );
    case "own_attendance":
      return t(
        "devices_page.unmatched.warn_own_attendance",
        "Already has attendance of their own on :count of the days this badge punched",
        { count: Number(warning.value ?? 0) },
      );
    case "moves_from":
      return warning.value
        ? t(
            "devices_page.unmatched.warn_moves_from",
            "This badge is now matched to :name and will be moved from them",
            { name: String(warning.value) },
          )
        : t(
            "devices_page.unmatched.warn_moves_from_other",
            "This badge is now matched to another employee and will be moved from them",
          );
    default:
      return warning.code;
  }
}
