/**
 * Readable names for audit log rows.
 *
 * The console printed the stored codes: `user.mfa_verified`, or
 * `user › mfa verified` once dots became arrows, and the entity column a PHP
 * class name such as `AttendanceIntegrityFlag`. Production, 2026-10-28: an
 * operator reading the trail in Amharic got English identifiers on every row.
 *
 * Every action the API records has an `audit.action.<code>` key in both
 * dictionaries. An action added later without one still reads as words: its
 * first segment through `audit.subject.*`, the rest humanised. The code itself
 * stays available to callers, because the action filter searches by it.
 */

type Translate = (
  key: string,
  fallback?: string,
  replacements?: Record<string, string | number>,
) => string;

function humanize(value: string): string {
  const words = value
    .replace(/([a-z0-9])([A-Z])/g, "$1 $2")
    .replace(/[._]+/g, " ")
    .trim()
    .toLowerCase();
  return words.charAt(0).toUpperCase() + words.slice(1);
}

export function auditActionLabel(action: string, t: Translate): string {
  const known = t(`audit.action.${action}`, "");
  if (known) return known;

  const [subject, ...rest] = action.split(".");
  const subjectLabel = t(`audit.subject.${subject}`, humanize(subject));

  if (rest.length === 0) return subjectLabel;
  return `${subjectLabel} · ${humanize(rest.join(" ")).toLowerCase()}`;
}

/** `App\Models\AttendanceRecord` → the record's name in the reader's language. */
export function auditEntityLabel(
  auditableType: string | null | undefined,
  t: Translate,
): string | null {
  if (!auditableType) return null;

  const base = auditableType.split("\\").pop() ?? auditableType;
  const key = base.replace(/([a-z0-9])([A-Z])/g, "$1_$2").toLowerCase();

  return t(`audit.entity.${key}`, humanize(base));
}
