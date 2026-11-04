/**
 * Honorifics that may lead a stored name. The record holds one `name` string
 * with no given-name field, and the greeting took its first word: "Good
 * evening, Prof." for "Prof. Elisabeth Cummings" (dashboard review,
 * 2026-11-03, PR 10). Ethiopian names put the given name first, so the first
 * word is right once a title is skipped.
 */
const HONORIFICS = new Set(
  [
    "mr",
    "mrs",
    "ms",
    "miss",
    "mx",
    "dr",
    "prof",
    "eng",
    "rev",
    "sir",
    "ato",
    "wro",
    "w/ro",
    "wrt",
    "w/rt",
    "wzro",
    "wzrt",
    "አቶ",
    "ወ/ሮ",
    "ወ/ሪት",
    "ወይዘሮ",
    "ወይዘሪት",
    "ዶ/ር",
    "ዶክተር",
    "ፕሮፌሰር",
    "ፕ/ር",
    "ኢንጂነር",
    "ኢ/ር",
  ].map((h) => h.toLowerCase()),
);

/** The word to greet someone by: the first word of the name that is not a title. */
export function givenName(name: string | null | undefined): string | undefined {
  if (!name) return undefined;
  const words = name.trim().split(/\s+/).filter(Boolean);
  const first = words.find(
    (word) => !HONORIFICS.has(word.replace(/\.+$/, "").toLowerCase()),
  );
  return first ?? words[0];
}
