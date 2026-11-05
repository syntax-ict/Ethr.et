"use client";

import { useEffect, useState } from "react";
import { useCurrentTenant } from "@/features/auth/api";
import { useCalendar } from "@/lib/calendar/calendar-context";
import { useT } from "@/lib/i18n/useT";

/**
 * The line at the top of a printed page: the organisation, the page, and
 * the date in the reader's calendar. Hidden on screen; `.print-header` in
 * the print stylesheet shows it.
 *
 * Paper is still the record in most Ethiopian offices (a signed leave form,
 * the month's attendance sheet, a headcount for a meeting), and a page
 * printed from the app carried no name, title or date of its own. The page
 * name is the document title, which every page sets through
 * `useDocumentTitle`, minus the product suffix.
 */
export function PrintHeader() {
  const { data: tenant } = useCurrentTenant();
  const { formatDate } = useCalendar();
  const { locale } = useT();
  const [title, setTitle] = useState("");

  useEffect(() => {
    const read = () => setTitle(document.title.split(" · ")[0] ?? "");
    read();
    const observer = new MutationObserver(read);
    const head = document.querySelector("head > title");
    if (head) observer.observe(head, { childList: true });
    return () => observer.disconnect();
  }, []);

  return (
    <div className="print-header hidden" aria-hidden="true">
      <span>
        <strong>{tenant?.name ?? ""}</strong>
        {title ? ` · ${title}` : ""}
      </span>
      <span>{formatDate(new Date(), locale)}</span>
    </div>
  );
}
