/**
 * An organisation type in the reader's language, or null for a value outside
 * the list (an old trade or a custom template record), which callers show as
 * the stored word.
 *
 * Shared by the organisation's own settings and the platform console, which
 * printed the stored value (`government`) under an Amharic heading.
 *
 * Literal `t()` calls, not a `settings.org_type_${value}` template: the i18n
 * gate only sees string-literal keys, so a dynamic one would never be checked
 * for en/am coverage.
 */
export function orgTypeLabel(
  value: string,
  t: (k: string, f: string) => string,
): string | null {
  switch (value) {
    case "general":
      return t("settings.org_type_general", "General company");
    case "private":
      return t("settings.org_type_private", "Private company");
    case "government":
      return t("settings.org_type_government", "Government");
    case "university":
      return t("settings.org_type_university", "University / TVET");
    case "ngo":
      return t("settings.org_type_ngo", "NGO");
    case "bank":
      return t("settings.org_type_bank", "Bank / Finance");
    case "hospital":
      return t("settings.org_type_hospital", "Hospital / Health");
    case "manufacturing":
      return t("settings.org_type_manufacturing", "Manufacturing");
    case "hotel":
      return t("settings.org_type_hotel", "Hotel / Hospitality");
    default:
      return null;
  }
}
