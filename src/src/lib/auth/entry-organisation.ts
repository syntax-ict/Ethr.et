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
