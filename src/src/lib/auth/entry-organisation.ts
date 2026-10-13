/**
 * The organisation named by the address `ethr.et/{slug}`.
 *
 * That address is every organisation's entry point. For one on the shared
 * host, the server answers it with the shared sign-in page itself
 * (OrganisationEntryController), so the page finds itself at `/{slug}`
 * rather than `/login`, and draws that organisation's landing.
 *
 * Only a single path segment that is a valid organisation slug counts, and
 * never the page's own route names: `/login` is the generic sign-in.
 */
const SLUG = /^[a-z0-9][a-z0-9-]{0,62}$/;
const OWN_ROUTES = new Set(["login", "register", "setup"]);

export function entryOrganisation(pathname: string | null): string | null {
  if (!pathname) return null;
  const segments = pathname.split(/[?#]/)[0].split("/").filter(Boolean);
  if (segments.length !== 1) return null;

  const slug = decodeURIComponent(segments[0]).toLowerCase();
  return SLUG.test(slug) && !OWN_ROUTES.has(slug) ? slug : null;
}

/**
 * The organisation a sign-in page was handed by its query string: `?org=`
 * (what the shared sign-in's own links carry, and what `ethr.et/{slug}` used
 * to redirect with) or `?tenant=` (what reset and activation e-mails carry).
 *
 * Without this, one click past `ethr.et/{slug}` — "Forgot password?", a text
 * code, a reset link — lost the organisation: the page fell back to the
 * generic sign-in, unbranded, asking for a subdomain the person had already
 * given (organisation-landing follow-up G5). Only a valid slug counts, and it
 * only names the page; nothing is stored until someone signs in.
 */
export function queryOrganisation(search: string | null): string | null {
  if (!search) return null;
  const params = new URLSearchParams(search);
  const raw = (params.get("org") ?? params.get("tenant") ?? "")
    .trim()
    .toLowerCase();

  return SLUG.test(raw) && !OWN_ROUTES.has(raw) ? raw : null;
}

/**
 * A sign-in flow link that keeps the organisation, when the page was reached
 * through its shared address rather than a host that names it.
 */
export function withOrganisation(path: string, slug: string | null): string {
  if (!slug) return path;
  const [base, query] = path.split("?", 2);
  const params = new URLSearchParams(query ?? "");
  params.set("org", slug);
  return `${base}?${params.toString()}`;
}

/**
 * Where "Back to sign in" goes: the organisation's own shared address when
 * there is one in play, else the generic sign-in.
 */
export function signInHref(slug: string | null): string {
  return slug ? `/${slug}` : "/login";
}
