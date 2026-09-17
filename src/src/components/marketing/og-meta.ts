/**
 * Where the social card lives, and how big it is.
 *
 * Separate from `og-card.tsx` because that module imports `next/og`, and these
 * three values are read by `generateMetadata` on every public page — importing
 * them from the card would pull the whole image runtime into the metadata path
 * for no reason.
 *
 * A fixed URL rather than Next's `opengraph-image.tsx` file convention, and the
 * reason is measured rather than stylistic. A file-convention image attaches to
 * the metadata of the segment it sits in and to children that do **not** declare
 * `openGraph` themselves — and every public page here declares one, because each
 * needs its own title and description in its own language. Confirmed against a
 * build: with `opengraph-image.tsx` in `(marketing)/[locale]`, `/en` carried an
 * `og:image` and `/en/pricing` carried none. The alternative was one copy of the
 * file in all eight segments; a single route the pages name explicitly is the
 * smaller thing to keep correct.
 */
export const OG_IMAGE_PATH = "/og.png";
export const OG_IMAGE_ALT = "ETHR — Ethiopian Workforce Operating System";
export const OG_IMAGE_SIZE = { width: 1200, height: 630 };
