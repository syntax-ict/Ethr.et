"use client";

import { createContext, useContext, type ReactNode } from "react";

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
 * The fallback keeps client-only callers (and any tree that forgot the
 * provider) working; it is only reached after hydration, so it cannot
 * reintroduce a mismatch.
 */
export function useHost(): string | null {
  const fromServer = useContext(HostContext);
  if (fromServer !== null) return fromServer;
  return typeof window !== "undefined" ? window.location.host : null;
}
