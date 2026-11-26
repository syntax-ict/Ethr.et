/**
 * Picks the reader's wording of a wizard catalogue field.
 *
 * The backend catalogues (industries, workforce and policy profiles, leave
 * types, shifts) carry each text as the English `field` plus a `field_<locale>`
 * for every shipped language, for example `label_am` and `label_om`. A locale
 * whose variant is missing or empty falls back to English rather than showing
 * a blank. The suffix follows the locale code, so adding a shipped language is
 * adding the backend field, not editing a list here.
 */
export function localizedField(
  record: object | null | undefined,
  field: string,
  locale: string,
): string {
  if (!record) return "";

  const row = record as Record<string, unknown>;
  const localized = locale === "en" ? null : row[`${field}_${locale}`];
  if (typeof localized === "string" && localized !== "") return localized;

  const base = row[field];
  return typeof base === "string" ? base : "";
}
