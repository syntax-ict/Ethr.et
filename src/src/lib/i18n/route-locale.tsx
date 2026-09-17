"use client";

import { createContext, useContext, useMemo } from "react";

import { localeHref } from "./config";

/**
 * The locale the *URL* says this page is in.
 *
 * Everything below `/am/*` and `/en/*` is generated once per locale at build
 * time, so on those routes the language is a fact about the request, not a
 * preference to be discovered after hydration. That distinction is the whole
 * reason these routes exist: `useT` previously resolved the locale from
 * localStorage, which the server cannot read, so every prerendered public page
 * was Amharic regardless of who asked for it.
 *
 * `null` outside the locale-prefixed tree. The dashboard, the auth screens and
 * the kiosk have no locale in their URL and keep the old behaviour — the stored
 * preference, read through `useSyncExternalStore`. `useT` picks whichever is
 * present, so neither side needs to know about the other.
 *
 * React context rather than a module-level variable on purpose: a module global
 * is shared by every concurrent render on the server, so two requests for
 * different locales would race and one would be served the other's language.
 */
const RouteLocaleContext = createContext<string | null>(null);

export function RouteLocaleProvider({
  locale,
  children,
}: {
  locale: string;
  children: React.ReactNode;
}) {
  return (
    <RouteLocaleContext.Provider value={locale}>
      {children}
    </RouteLocaleContext.Provider>
  );
}

export function useRouteLocale(): string | null {
  return useContext(RouteLocaleContext);
}

/**
 * `localeHref` bound to the locale the current route is in.
 *
 * Outside the locale-prefixed tree it is the identity function, so the same
 * header and footer components work unchanged on `/login` and `/dashboard`.
 */
export function useLocaleHref(): (path: string) => string {
  const locale = useRouteLocale();
  return useMemo(
    () => (path: string) => (locale ? localeHref(locale, path) : path),
    [locale],
  );
}
