"use client";

import { usePathname } from "next/navigation";

import { useHydrated } from "@/lib/hooks/useHydrated";
import {
  routeIdFromPathname,
  STATIC_EXPORT_ROUTE_ID,
} from "@/lib/static-export";

/**
 * The id of the entity this screen is showing, whichever way the page was built.
 *
 * Takes the route param the server resolved and returns it unchanged in every
 * deployment that renders per-id — `output: "standalone"`, `next dev`, the test
 * suite. There is no extra render and no behaviour to regress there.
 *
 * Under `output: "export"` the param is `STATIC_EXPORT_ROUTE_ID`, because the
 * one shell that exists was built under it (see `@/lib/static-export`). The real
 * id then comes from `usePathname()`, which reflects the browser's URL rather
 * than the prerendered one.
 *
 * **Why the id is not derived during the first render.** The obvious version is
 * wrong: the shell's HTML was rendered for the sentinel, so a first render that
 * already knows the real id disagrees with it and React raises hydration error
 * #418. `useHydrated()` is the fix and carries the reasoning; `usePathname()` is
 * what re-renders this hook on a client-side navigation afterwards.
 *
 * Returns `null` while the shell is hydrating, so callers must treat "no id yet"
 * as loading. Every one of them already has that branch for the fetch.
 */
export function useRouteId(routeId: string): string | null {
  const pathname = usePathname();
  const hydrated = useHydrated();

  if (routeId !== STATIC_EXPORT_ROUTE_ID) return routeId;

  return hydrated ? routeIdFromPathname(pathname) : null;
}
