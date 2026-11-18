"use client";

import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { useT } from "@/lib/i18n/useT";

export interface PrintScopeItem {
  label: string;
  /** The text as the filter shows it. Empty or null prints as "All". */
  value: string | null | undefined;
}

/**
 * What a printed list is a list of: the filters and dates that were applied
 * on screen, as one line under the page header
 * ("Date: Hidar 1 to Hidar 9, 2019 · Status: Late"). The filter bars are
 * not printed, so without this the paper cannot say why it holds these rows.
 *
 * Hidden on screen; `.print-scope` in the print stylesheet shows it. A
 * filter that is not set reads "All", because a missing line would be
 * indistinguishable from a page that forgot to say.
 */
export function PrintScope({ items }: { items: PrintScopeItem[] }) {
  const { t } = useT();
  if (items.length === 0) return null;

  return (
    <p className="print-scope hidden" aria-hidden="true">
      {items.map((item, index) => (
        <span key={item.label}>
          {index > 0 ? " · " : ""}
          <span className="print-scope-label">{item.label}</span>
          {": "}
          {item.value ? item.value : t("print.all", "All")}
        </span>
      ))}
    </p>
  );
}

/**
 * An ISO date range as the reader's calendar says it: "Hidar 1 to Hidar 9,
 * 2019", "from Hidar 1, 2019", "until Hidar 9, 2019", or "" when neither end
 * is set (which `PrintScope` prints as "All").
 */
export function usePrintDateRange() {
  const { t } = useT();
  const { formatDate } = useDateFormatters();

  return (from: string | null | undefined, to: string | null | undefined) => {
    if (from && to) {
      return t("print.range", ":from to :to", {
        from: formatDate(from),
        to: formatDate(to),
      });
    }
    if (from) return t("print.from", "from :from", { from: formatDate(from) });
    if (to) return t("print.until", "until :to", { to: formatDate(to) });
    return "";
  };
}
