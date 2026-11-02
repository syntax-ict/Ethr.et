"use client";

import { useEffect } from "react";
import { usePathname } from "next/navigation";
import { getRouteMeta, routeText } from "@/lib/route-meta";
import { useT } from "@/lib/i18n/useT";
import { usePermissions } from "@/lib/hooks/usePermissions";

const APP_NAME = process.env.NEXT_PUBLIC_APP_NAME ?? "ETHR";

/**
 * Keeps `document.title` in step with the current dashboard route.
 *
 * Every authenticated page is a client component, so none of them can export
 * Next's `metadata` — the result was that all seventy of them inherited the root
 * layout's title and every browser tab read "ETHR — Ethiopian Workforce
 * Operating System". In a product where an HR administrator routinely has
 * payroll, attendance and an employee record open side by side, the tab strip
 * carried no information at all.
 *
 * Driving this from `ROUTE_META` — the registry the breadcrumb and title bar
 * already use — means a route's title, breadcrumb and heading cannot drift
 * apart, and `getRouteMeta` gives dynamic segments (`/employees/[id]`) a
 * sensible label instead of a raw ULID.
 *
 * Public routes ((marketing), and the (auth) group) keep real server-rendered
 * `metadata` exports: those pages are crawlable and a client-side title would
 * be invisible to anything that does not run JavaScript.
 *
 * Setting the title once was not enough. Next streams each route's metadata
 * and writes its `<title>` when that arrives, which on a client navigation can
 * be after this effect ran. Production, 2026-10-28: opening an organisation
 * from the console's list left the tab reading the layout's default for as long
 * as the page stayed open. So the hook keeps watching `<head>` and puts its
 * title back whenever something else replaces it.
 */
export function useDocumentTitle(): void {
  const pathname = usePathname();
  const { t } = useT();
  const { can } = usePermissions();
  // Worked out here rather than in the effect: the label changes with the
  // language and with who is looking, and the effect only needs to know when.
  const meta = getRouteMeta(pathname);
  const label = meta ? routeText(pathname, meta, can, t).label : null;

  useEffect(() => {
    if (label === null) return;

    const title = `${label} · ${APP_NAME}`;
    const apply = () => {
      if (document.title !== title) document.title = title;
    };

    apply();
    const observer = new MutationObserver(apply);
    observer.observe(document.head, {
      childList: true,
      subtree: true,
      characterData: true,
    });

    return () => observer.disconnect();
  }, [label]);
}
