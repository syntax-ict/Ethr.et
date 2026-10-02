import type { Metadata } from "next";
import { headers } from "next/headers";
import { HostProvider } from "@/lib/auth/host-provider";
import { IS_STATIC_EXPORT } from "@/lib/build-target";
import { baseMetadata, RootShell } from "../root-shell";
import { DEFAULT_LOCALE } from "@/lib/i18n/translations";
import { AuthLayoutClient } from "./auth-layout-client";

export const metadata: Metadata = baseMetadata;

/**
 * Server component so the auth tree knows the request's hostname on the very
 * first render.
 *
 * Under subdomain tenancy the hostname decides which organisation the login
 * page is for, and the client used to read it from `window.location.host` —
 * unavailable during SSR. Server and client therefore rendered different
 * headings and disagreed about whether to show the organisation field, which
 * React reports as hydration error #418 on every tenant login page.
 *
 * Reading `headers()` here opts these routes out of static rendering. That is
 * deliberate and correctly scoped: it applies to the auth route group only —
 * which is host-dependent by definition — and not to the marketing or app
 * shells. Measured 2026-09-26: those seven routes — `/login`, its four
 * sub-pages, `/register` and `/impersonate/claim` — are the *only* dynamic
 * routes in the whole application, and this line is the whole reason.
 *
 * **Skipped under `output: "export"`, because there is no request to read.**
 * `next build` stops outright there: *"Route /login with dynamic = \"error\"
 * couldn't be rendered statically because it used headers()"*. The host then
 * comes from the browser instead — `useHost()` falls back to
 * `window.location.host` once hydrated, which is what makes that safe. This is
 * a narrower change than `../../deployment/shared-hosting/DEPLOYMENT.md` §5
 * step 3 prescribed: it said to *replace* the `headers()` read, which would have
 * given the VPS rollback path the export target's first-paint flash for nothing.
 * The read is kept where there is a server to read from.
 *
 * It is also a root layout now that `app/layout.tsx` is gone, so it renders the
 * shared `<html>`/`<body>` shell itself. `DEFAULT_LOCALE`: the auth routes carry
 * no language segment, and the reader's stored preference is applied after
 * hydration as it always was.
 */
export default async function AuthLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  const host = IS_STATIC_EXPORT ? null : (await headers()).get("host");

  return (
    <RootShell lang={DEFAULT_LOCALE}>
      <HostProvider host={host}>
        <AuthLayoutClient>{children}</AuthLayoutClient>
      </HostProvider>
    </RootShell>
  );
}
