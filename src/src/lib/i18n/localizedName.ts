/**
 * Picks the Amharic name of a record when the UI is in Amharic and one exists.
 *
 * Several tables carry a bilingual pair (`name` + `name_am`): holidays,
 * departments, branches, leave types. The Amharic column is nullable — rows
 * created before bilingual names existed, or by an admin who only filled in
 * English, have it as null — so this always falls back to `name` rather than
 * rendering an empty label.
 */
export function localizedName(
  record: { name: string; name_am?: string | null } | null | undefined,
  locale: string,
): string {
  if (!record) return "";

  return locale === "am" && record.name_am ? record.name_am : record.name;
}
