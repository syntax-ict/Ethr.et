/**
 * The key families that appear on a public page.
 *
 * These exist because the public site is prerendered in English while `en.json`
 * is loaded lazily, and the two facts together produce a defect that is easy to
 * miss: the *server* has the dictionary by the time it renders (the dynamic
 * import resolves during the build), so `/en/faq` ships correct English HTML —
 * but the browser's first render, during hydration, does not, and `t()` with no
 * fallback returns the raw key. React then replaces the server's text with
 * `marketing.faq_page.what_is_q` until the chunk lands. Confirmed by reading the
 * build output, which carries the real sentences.
 *
 * Seventeen call sites on the public pages pass a template-literal key over an
 * enumerated list (`t(\`marketing.features.${key}\`)`) and so cannot carry an
 * inline fallback without duplicating forty sentences of copy. So the layout
 * hands the browser the subset of the dictionary these prefixes cover — 27 KB
 * raw, **7.5 KB gzipped**, against 186 KB / 45 KB for the whole file — and it is
 * registered synchronously before anything renders.
 *
 * A key *with* a fallback is safe outside this list, and the gate allows it: the
 * browser's first render produces the fallback, the server produced `en.json`,
 * and a separate check holds those two equal. Widening the list to cover five
 * such strings in `product-flow.tsx` would have taken the payload from 7.5 KB to
 * 13.2 KB, which is the measurement that settled the rule.
 *
 * `scripts/i18n-check.js` reads this list and fails when a public page uses a
 * key outside it, which is what stops the subset silently going stale. Adding a
 * family here is the fix when it does.
 */
export const PUBLIC_KEY_PREFIXES = [
  "marketing.",
  "common.",
  "a11y.",
  "language.",
  "legal.",
  "error.",
  "not_found.",
  "nav.",
] as const;

export function isPublicKey(key: string): boolean {
  return PUBLIC_KEY_PREFIXES.some((prefix) => key.startsWith(prefix));
}

export function publicSubset(
  dictionary: Record<string, string>,
): Record<string, string> {
  const subset: Record<string, string> = {};
  for (const [key, value] of Object.entries(dictionary)) {
    if (isPublicKey(key)) subset[key] = value;
  }
  return subset;
}
