import { genderOptionLabel } from "@/lib/options/gender";

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
  if (gender === "male" || gender === "female") {
    return genderOptionLabel(t, gender);
  }
  if (gender === "" || gender === null || gender === undefined) {
    return t("executive_dashboard.gender_unrecorded", "Not recorded");
  }
  return gender;
}

/**
 * The gender split with every group named, and groups that read the same
 * added together. A person whose gender is NULL and one whose gender is an
 * empty string (an import can store either) are one group to a reader, not
 * two rows both called "Not recorded".
 */
export function namedGenders(
  t: Translate,
  rows: ReadonlyArray<{ gender: string | null; count: number }>,
): { gender: string; count: number }[] {
  const merged = new Map<string, number>();
  for (const row of rows) {
    const label = genderLabel(t, row.gender);
    merged.set(label, (merged.get(label) ?? 0) + row.count);
  }
  return Array.from(merged, ([gender, count]) => ({ gender, count }));
}
