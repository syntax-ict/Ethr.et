"use client";

import { createContext, useContext, type ReactNode } from "react";

import { useHydrated } from "@/lib/hooks/useHydrated";

/**
 * The request's Host header, made available to client components.
 *
 * Why this exists: `useAuthHostContext` used to derive the host from
 * `window.location.host` during render, which is `null` on the server and the
 * real hostname on the client. Under subdomain tenancy those two produce
 * *different markup* — the server rendered the generic "Sign in to ETHR"
 * heading with the organisation field visible, the client rendered
 * "Sign in to {Tenant}" with the field hidden — so every tenant login page
 * threw React error #418 (hydration text mismatch) and flashed the org field
 * before hiding it. It never showed up on `localhost` because without a root
 * domain there is no subdomain to disagree about.
 *
 * The server knows the host all along: it is on the request. Passing it down
 * makes the first client render identical to the server's.
 */
const HostContext = createContext<string | null>(null);

export function HostProvider({
  host,
  children,
}: {
  host: string | null;
  children: ReactNode;
}) {
  return <HostContext.Provider value={host}>{children}</HostContext.Provider>;
}

/**
 * The host as the server saw it, falling back to the browser's own value.
 *
 * The fallback keeps client-only callers (and any tree that forgot the provider)
 * working, and under `output: "export"` it is not a fallback at all but the only
 * source: there is no request when the HTML is built, so the provider is handed
 * `null` and every auth page takes this path.
 *
 * **It is gated on `useHydrated()`, and the comment this replaced was wrong.**
 * That comment said the fallback "is only reached after hydration, so it cannot
 * reintroduce a mismatch." True while the provider supplies a host — the
 * `fromServer !== null` line returns first — and false the moment it does not:
 * `typeof window !== "undefined"` is already true *during* hydration, so the
 * first client render would produce the real hostname against HTML built with
 * `null`, which is the very #418 this file exists to remove. Measured 2026-09-26.
 *
 * The cost on that target is one render with no host — the login page shows its
 * generic heading and the organisation field before resolving. That is the
 * trade `docs/deployment/shared-hosting/DEPLOYMENT.md` §5 step 3 records as
 * accepted, not a regression: a flash beats a hydration error, and nothing about
 * *authorization* depends on this value (see `useAuthHostContext` — it decides
 * which heading and which field to show; the API decides what data exists).
 */
export function useHost(): string | null {
  const fromServer = useContext(HostContext);
  const hydrated = useHydrated();

  if (fromServer !== null) return fromServer;

  return hydrated ? window.location.host : null;
}
