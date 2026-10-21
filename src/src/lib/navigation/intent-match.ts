/**
 * Deterministic matching of what a person typed against the names and
 * phrases a palette row answers to.
 *
 * cmdk's default filter is fuzzy: it accepts the query's letters anywhere in
 * the row, in order, with any gaps. That is fine for "empl" against
 * "Employees", and wrong for a sentence: "who is absent today" has enough
 * letters to light up half the navigation. Rows here match on whole words, so
 * a phrase either names a row or it does not, and a test can say which.
 *
 * Scores, highest first:
 *   1    the query is one of the row's phrases, or its label
 *   0.9  a phrase or the label contains the query
 *   0.95 / 0.85  the same two tests with the filler words ("i want to",
 *        "the", "my") taken out of both the query and the phrases
 *   0.65 to 0.9  each content word of the query is the start of a word in
 *        the row, scaled by how many matched; at least half must, and at
 *        least one
 *   0    otherwise
 */

const STOP_WORDS = new Set([
  // English function words and the verbs people put in front of a goal.
  "i",
  "a",
  "an",
  "the",
  "my",
  "our",
  "your",
  "to",
  "of",
  "for",
  "me",
  "is",
  "are",
  "it",
  "this",
  "that",
  "want",
  "need",
  "would",
  "like",
  "please",
  "show",
  "see",
  "view",
  "open",
  "go",
  "get",
  "let",
  "can",
  "how",
  "do",
  "does",
  // Amharic: "I want", "to see", "to open", "please" and the copula.
  "እፈልጋለሁ",
  "ማየት",
  "መክፈት",
  "እባክህ",
  "እባክሽ",
  "እባክዎ",
  "ነው",
  "ናቸው",
  "ላይ",
  "ወደ",
]);

const PUNCTUATION = /[?!.,;:'"()[\]{}/\\።፣፤፥፦፧፨-]+/g;

export function normalise(text: string): string {
  return text
    .toLowerCase()
    .replace(PUNCTUATION, " ")
    .replace(/\s+/g, " ")
    .trim();
}

function words(text: string): string[] {
  return normalise(text).split(" ").filter(Boolean);
}

function isContentWord(word: string): boolean {
  if (STOP_WORDS.has(word)) return false;
  // One Latin letter says nothing; one Ethiopic syllable can be a word.
  return word.length > 1 || !/^[a-z0-9]$/.test(word);
}

function wordMatches(query: string, candidate: string): boolean {
  if (candidate.startsWith(query)) return true;
  // "payslips" typed against "payslip": the row's word is a stem of the query.
  return candidate.length >= 3 && query.startsWith(candidate);
}

/**
 * How well `query` names a row whose label and phrases are `phrases`.
 * Phrases are matched whole first; words are the fallback.
 */
export function intentScore(query: string, phrases: readonly string[]): number {
  const q = normalise(query);
  if (q === "") return 1;

  const normalised = phrases.map(normalise).filter(Boolean);
  if (normalised.some((p) => p === q)) return 1;
  if (normalised.some((p) => p.includes(q))) return 0.9;

  // The same comparison with the filler taken out of both sides, so "i want
  // to prepare the payroll" is "prepare payroll" and "approve a leave request"
  // answers "approve leave request".
  const queryWords = words(q).filter(isContentWord);
  if (queryWords.length === 0) return 0;
  const queryCore = queryWords.join(" ");
  const cores = normalised.map((p) =>
    p.split(" ").filter(isContentWord).join(" "),
  );
  if (cores.some((p) => p === queryCore)) return 0.95;
  if (cores.some((p) => p.includes(queryCore))) return 0.85;

  const rowWords = new Set(normalised.flatMap((p) => p.split(" ")));
  let matched = 0;
  for (const word of queryWords) {
    for (const candidate of rowWords) {
      if (wordMatches(word, candidate)) {
        matched += 1;
        break;
      }
    }
  }
  if (matched === 0) return 0;
  const ratio = matched / queryWords.length;
  if (ratio < 0.5) return 0;
  return 0.4 + 0.5 * ratio;
}

/** Split a `|`-separated phrase list from a dictionary value. */
export function splitPhrases(value: string | undefined): string[] {
  if (!value) return [];
  return value
    .split("|")
    .map((p) => p.trim())
    .filter(Boolean);
}
