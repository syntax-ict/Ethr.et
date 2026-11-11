/**
 * The API's answer when the person being added looks like someone the
 * organisation already has (409 from `POST /employees`, `EmployeeIntake`).
 *
 * `employee-exists` is a certain match with a current employee and is not
 * overruled. `employee-needs-confirmation` is someone who may be this person
 * (a weaker match, a leaver, a deleted record); the same request with
 * `confirm_new` adds them.
 */
export interface EmployeeLikeness {
  code: "employee-exists" | "employee-needs-confirmation";
  outcome: "matched" | "needs_review" | "archived" | "former_employee";
  detail: string;
  candidates: { public_id: string; name: string; reasons: string[] }[];
}

const CODES = ["employee-exists", "employee-needs-confirmation"];

/** The likeness a failed save carries, or null for any other failure. */
export function employeeLikeness(error: unknown): EmployeeLikeness | null {
  const response = (
    error as { response?: { status?: number; data?: Record<string, unknown> } }
  )?.response;
  const data = response?.data;

  if (
    response?.status !== 409 ||
    !data ||
    typeof data.code !== "string" ||
    !CODES.includes(data.code)
  ) {
    return null;
  }

  const candidates = Array.isArray(data.candidates) ? data.candidates : [];

  return {
    code: data.code as EmployeeLikeness["code"],
    outcome: (typeof data.outcome === "string"
      ? data.outcome
      : "needs_review") as EmployeeLikeness["outcome"],
    detail: typeof data.detail === "string" ? data.detail : "",
    candidates: candidates
      .filter(
        (c): c is { public_id: string; name: string; reasons?: unknown } =>
          typeof c === "object" &&
          c !== null &&
          typeof (c as { public_id?: unknown }).public_id === "string" &&
          typeof (c as { name?: unknown }).name === "string",
      )
      .map((c) => ({
        public_id: c.public_id,
        name: c.name,
        reasons: Array.isArray(c.reasons)
          ? c.reasons.filter((r): r is string => typeof r === "string")
          : [],
      })),
  };
}

/**
 * `IdentityResolver` reasons: known signal names as-is, `name~0.85` for the
 * name-similarity signal (the score is part of the key).
 */
export function matchReasonLabel(
  reason: string,
  t: (key: string, fallback?: string) => string,
): string {
  if (reason.startsWith("name~")) {
    const pct = Math.round(Number(reason.slice("name~".length)) * 100);
    return `${t("setup2.reason_name", "name")} ~${pct}%`;
  }
  return t(`setup2.reason_${reason}`, reason.replace(/_/g, " "));
}
