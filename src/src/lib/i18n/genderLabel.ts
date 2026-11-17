type Translate = (key: string, fallback?: string) => string;

/**
 * A stored gender as a person reads it.
 *
 * The values are `male`, `female` (StoreEmployeeRequest allows no others) or
 * the empty string (or null), which is how `groupBy('gender')` files the
 * employees whose gender was never recorded. That group used to be a legend
 * item and a table row with no name, and the stored words were shown in
 * English under every language.
 */
export function genderLabel(
  t: Translate,
  gender: string | null | undefined,
): string {
  if (gender === "male") return t("common.male", "Male");
  if (gender === "female") return t("common.female", "Female");
  if (gender === "" || gender === null || gender === undefined) {
    return t("executive_dashboard.gender_unrecorded", "Not recorded");
  }
  return gender;
}
