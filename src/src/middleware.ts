import { NextResponse, type NextRequest } from "next/server";

import { hostContext } from "@/lib/auth/host-context";
import {
  LOCALE_COOKIE,
  localeHref,
  negotiateLocale,
  parseAcceptLanguage,
} from "@/lib/i18n/config";
import { PUBLIC_ROUTES } from "@/lib/site-url";

/**
 * Hostname decides which application a request is allowed to reach.
 *
 *   ethr.et          public / marketing   — no tenant, no admin console
 *   admin.ethr.et    platform admin       — super admin only, never a tenant
 *   {tenant}.ethr.et tenant application   — tenant resolved from the hostname
 *
 * Until this existed the admin console was a *path*, served identically on every
 * hostname: `habru.ethr.et/admin` rendered the platform console shell for anyone
 * who typed it. Data was never exposed — `Gate::authorize('admin.manage')` and
 * `<RoleGate minRole="super_admin">` both refuse — but the boundary was a
 * permission check rather than a structural one, so a single admin screen added
 * without a gate would have been reachable from a tenant host.
 *
 * Nginx refuses `/admin` on non-platform hosts too (infrastructure/nginx.conf).
 * That is the authoritative control; this exists so the same rule holds in
 * development and in any deployment fronted by something other than that nginx,
 * and so the user gets a redirect rather than a bare 404.
 *
 * Inert without NEXT_PUBLIC_ROOT_DOMAIN. On `localhost` there are no subdomains
 * to read, so enforcing a hostname model would simply lock development out —
 * the same reason ResolveTenant only honours X-Tenant in local/testing.
 */

const ROOT_DOMAIN = process.env.NEXT_PUBLIC_ROOT_DOMAIN?.trim() || null;

/**
 * The unprefixed public URLs, each of which now forwards into a language.
 *
 * `Set<string>` rather than the readonly tuple's own union, so `pathname` can be
 * tested against it without a cast.
 */
const LOCALE_ENTRY_PATHS = new Set<string>(PUBLIC_ROUTES);

export function middleware(request: NextRequest) {
  const { pathname } = request.nextUrl;

  /* Locale negotiation comes first, deliberately.
   *
   * Everything below returns early when NEXT_PUBLIC_ROOT_DOMAIN is unset —
   * which is every development machine — so locale logic placed after it would
   * be dead exactly where it gets written and tested, and would first run in
   * production.
   *
   * This is an optimisation, not the mechanism. `(root)/locale-redirect.tsx`
   * performs the same negotiation in the browser and is what actually
   * guarantees the behaviour: middleware does not run under `output: "export"`,
   * which is still a live possibility for this deployment, and it cannot read
   * localStorage at all. Saving the visitor a client-side hop is all this does.
   *
   * It deliberately does not *write* the cookie. Only an explicit choice in the
   * language switcher does that — recording a guess from `Accept-Language` as
   * though it were a preference is how a reader ends up stuck in a language
   * they never picked.
   */
  if (LOCALE_ENTRY_PATHS.has(pathname)) {
    const locale = negotiateLocale([
      request.cookies.get(LOCALE_COOKIE)?.value,
      ...parseAcceptLanguage(request.headers.get("accept-language")),
    ]);

    const url = request.nextUrl.clone();
    url.pathname = localeHref(locale, pathname);
    return NextResponse.redirect(url);
  }

  if (!ROOT_DOMAIN) return NextResponse.next();

  const host = request.headers.get("host") ?? "";
  const context = hostContext(host, ROOT_DOMAIN);

  const isAdminRoute = pathname === "/admin" || pathname.startsWith("/admin/");

  // The console lives on exactly one host.
  if (isAdminRoute && context !== "platform") {
    const url = request.nextUrl.clone();
    url.host = `admin.${ROOT_DOMAIN}`;

    // The port the *browser* used, from the Host header — not the one Next is
    // listening on, and not unconditionally none.
    //
    // Assigned explicitly in both directions because the URL `host` setter
    // leaves the existing port in place when the value it is given carries
    // none, so neither the old port nor the new host can be trusted to be
    // right on its own. Clearing it always (what this did before) is correct
    // behind nginx — the browser is on 443 and the container's :3000 must not
    // leak — but sent the browser to `admin.localhost:80` with the hostname
    // model switched on locally, where the console is at `admin.localhost:3000`
    // and nothing is listening on 80.
    url.port = host.split(":")[1] ?? "";

    return NextResponse.redirect(url);
  }

  // And the platform host serves only the console. Sending a tenant route here
  // to `/admin` rather than 404ing keeps a super admin who lands on a stale
  // bookmark somewhere useful — and there is no tenant context on this host to
  // render a tenant page with anyway.
  if (context === "platform" && !isAdminRoute && isTenantRoute(pathname)) {
    const url = request.nextUrl.clone();
    url.pathname = "/admin";
    return NextResponse.redirect(url);
  }

  return NextResponse.next();
}

/**
 * Routes that only mean something inside a tenant. Deliberately a list rather
 * than "anything not /admin": the platform host still has to serve /login,
 * /logout, the marketing shell and Next's own assets.
 */
function isTenantRoute(pathname: string): boolean {
  return [
    "/dashboard",
    "/employees",
    "/attendance",
    "/leave",
    "/payroll",
    "/organization",
    "/directory",
    "/devices",
    "/shifts",
    "/reports",
    "/settings",
    "/announcements",
    "/approvals",
    "/profile",
  ].some((route) => pathname === route || pathname.startsWith(`${route}/`));
}

export const config = {
  // Skip Next internals, the API proxy and static files — matching them would
  // put a redirect in front of every asset request for no benefit.
  matcher: ["/((?!api|_next/static|_next/image|favicon.ico|sw.js|manifest).*)"],
};
