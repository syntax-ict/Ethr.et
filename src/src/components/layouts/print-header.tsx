"use client";

import { useEffect, useState } from "react";
import { flushSync } from "react-dom";
import { useCurrentTenant, useCurrentUser } from "@/features/auth/api";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { useT } from "@/lib/i18n/useT";
import { usePrintTitleOverride } from "@/components/layouts/print-title";

/**
 * The frame at the top of a printed page: who printed what, for whom and
 * when. Hidden on screen; `.print-header` in the print stylesheet shows it.
 *
 * Left: the organisation's logo (when it has one), its name, and the title
 * of the page on its own line. Right: when it was printed, in the reader's
 * calendar and clock, and by whom.
 *
 * A browser print is a working copy of the screen, and the paper has to say
 * so: a signed leave form, the month's attendance sheet or a headcount for a
 * meeting must carry its own name, date and author, or it cannot be told
 * from last month's. The title is the document title every page sets
 * through `useDocumentTitle`, minus the product suffix, unless the page
 * names itself with `usePrintTitle`.
 */
export function PrintHeader() {
  const { data: tenant } = useCurrentTenant();
  const { data: user } = useCurrentUser();
  const { formatDateTime } = useDateFormatters();
  const { t } = useT();
  const override = usePrintTitleOverride();
  const [documentTitle, setDocumentTitle] = useState("");
  const [printedAt, setPrintedAt] = useState(() => new Date().toISOString());

  useEffect(() => {
    const read = () => setDocumentTitle(document.title.split(" · ")[0] ?? "");
    read();
    const observer = new MutationObserver(read);
    const head = document.querySelector("head > title");
    if (head) observer.observe(head, { childList: true });
    return () => observer.disconnect();
  }, []);

  // The time on the paper is when the paper was printed, not when the page
  // was opened. The browser fires this just before it lays the page out, so
  // the new time is flushed to the DOM before it does.
  useEffect(() => {
    const stamp = () => flushSync(() => setPrintedAt(new Date().toISOString()));
    window.addEventListener("beforeprint", stamp);
    return () => window.removeEventListener("beforeprint", stamp);
  }, []);

  const title = override ?? documentTitle;
  const logo = tenant?.logo_path ?? null;

  return (
    <div className="print-header hidden" aria-hidden="true">
      <div className="print-header-id">
        {logo ? (
          /* eslint-disable-next-line @next/next/no-img-element */
          <img
            src={logo}
            alt=""
            className="print-header-logo"
            onError={(e) => {
              e.currentTarget.style.display = "none";
            }}
          />
        ) : null}
        <div>
          <div className="print-header-org">{tenant?.name ?? ""}</div>
          {title ? <div className="print-header-title">{title}</div> : null}
        </div>
      </div>
      <div className="print-header-meta">
        <div>
          {t("print.printed", "Printed :when", {
            when: formatDateTime(printedAt),
          })}
        </div>
        {user?.name ? (
          <div>{t("print.printed_by", "by :name", { name: user.name })}</div>
        ) : null}
      </div>
    </div>
  );
}
