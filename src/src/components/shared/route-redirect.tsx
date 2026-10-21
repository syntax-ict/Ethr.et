"use client";

import { useEffect } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useT } from "@/lib/i18n/useT";

/**
 * A page that has moved. The production frontend is a static export on
 * shared hosting, with no server redirects or middleware, so an old address
 * is kept as a page that replaces itself with the new one: bookmarks, links
 * in emails and links the API builds keep working.
 *
 * The query string travels with it, so `?tab=` and filters survive the move.
 * The link is for the moment before the script runs, and for anyone without
 * one.
 */
export function RouteRedirect({ to }: { to: string }) {
  const router = useRouter();
  const { t } = useT();

  useEffect(() => {
    router.replace(`${to}${window.location.search}${window.location.hash}`);
  }, [router, to]);

  return (
    <p className="py-16 text-center text-sm text-muted-foreground">
      {t("common.page_moved", "This page has moved.")}{" "}
      <Link href={to} className="font-medium text-primary hover:underline">
        {t("common.go_to_new_page", "Go to the new page")}
      </Link>
    </p>
  );
}
