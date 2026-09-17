import type { Metadata } from "next";
import { headers } from "next/headers";
import { HostProvider } from "@/lib/auth/host-provider";
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
 * shells.
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
  const host = (await headers()).get("host");

  return (
    <RootShell lang={DEFAULT_LOCALE}>
      <HostProvider host={host}>
        <AuthLayoutClient>{children}</AuthLayoutClient>
      </HostProvider>
    </RootShell>
  );
}
