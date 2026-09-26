/**
 * Serving `[id]` routes from a static export.
 *
 * `output: "export"` demands `generateStaticParams()` for every dynamic
 * segment, and it builds one HTML file per value that function returns. ETHR's
 * ids are tenant data — 26-character ULIDs created after the build — so there
 * is no set of values to enumerate, and returning `[]` emits nothing and 404s
 * every real id. `docs/deployment/shared-hosting/DEPLOYMENT.md` §5 step 4
 * recorded that dead end on 2026-09-18 without a way out of it.
 *
 * The way out is to build **one** shell per route under a sentinel id and let
 * the web server hand that shell to every id, so the id arrives in the URL
 * rather than in the build. `docs/deployment/shared-hosting/.htaccess` carries
 * the rewrite; this module carries the two halves the application needs.
 *
 * Measured against Next 16.3.5 / React 19.2.7, 2026-09-26 — see
 * `docs/audit/BASELINE.md` §19. The finding that decides the design: on a
 * shell served for another id, `useParams()` returns the **sentinel**, because
 * params come from the payload that was prerendered. `usePathname()` returns
 * the **real** path. So the id must be read from the path, which is what
 * `useRouteId` does.
 */

/**
 * The one `[id]` every exported shell is built under.
 *
 * Deliberately not a plausible ULID: it appears in `out/employees/__id__.html`
 * and in the rewrite, and a reader who meets it needs to see immediately that
 * it is a placeholder rather than someone's employee record. The double
 * underscores also keep it outside Crockford base32, so it can never collide
 * with a real `public_id`.
 */
export const STATIC_EXPORT_ROUTE_ID = "__id__";

/**
 * What `generateStaticParams()` returns on an `[id]` route.
 *
 * One entry, not none. `[]` is what `DEPLOYMENT.md` §5 step 4 prescribed and it
 * is why the step did not work: an empty list is a valid answer to "which pages
 * exist" and the answer it gives is "none".
 */
export function staticExportIdParams(): Array<{ id: string }> {
  return [{ id: STATIC_EXPORT_ROUTE_ID }];
}

/**
 * The id the browser is actually on, from `/employees/<id>`.
 *
 * Returns `null` rather than an empty string when there is nothing usable, so a
 * caller that forgets to check gets a disabled query instead of a request to
 * `/employees/`. Query strings and a trailing slash are stripped; the segment is
 * decoded, and a malformed escape falls back to the raw text instead of throwing
 * — `decodeURIComponent("%zz")` is a `URIError`, and a crashed render is a worse
 * answer than a wrong id that the API will reject anyway.
 */
export function routeIdFromPathname(pathname: string | null): string | null {
  if (!pathname) return null;

  const segment = pathname
    .split(/[?#]/)[0]
    .replace(/\/+$/, "")
    .split("/")
    .pop();

  if (!segment) return null;

  try {
    return decodeURIComponent(segment);
  } catch {
    return segment;
  }
}
