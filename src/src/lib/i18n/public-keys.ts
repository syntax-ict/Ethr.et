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
 * **Since 2026-10-03 this applies to Amharic too, and with no exception.** A key
 * with an inline English fallback used to be allowed outside this list — safe
 * while `am.json` was eager on every page, because the fallback only ever stood
 * in for English. Now that Amharic also reaches the browser as this projection,
 * such a key renders Amharic on the server and English in the browser's first
 * render. The five strings in `product-flow.tsx` that used the exemption were
 * re-keyed under `marketing.product_flow.*` (five strings, rather than widening
 * this list to their whole families, which measured 7.5 → 13.2 KB), and the gate
 * now refuses any public key outside these prefixes.
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
  // lib/utils/date.ts's timeAgo, translated since 2026-10-09; that module is
  // imported by public pages too.
  "time.",
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
