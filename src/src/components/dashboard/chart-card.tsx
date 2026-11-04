"use client";

import { useState, type ReactNode } from "react";
import { useT } from "@/lib/i18n/useT";
import { cn } from "@/lib/utils";

/** How a chart card is being read: drawn, or as the figures behind it. */
export type ChartView = "chart" | "table";

/**
 * The frame every dashboard chart and panel sits in: a title, an optional
 * one-line description, an action slot on the right (a range picker, a "View
 * all" link), then the content. One header size for every panel, where the
 * dashboards used `text-sm`, `text-base` and `text-base` with an icon for the
 * same role, sometimes on one page.
 *
 * Children given as a function receive the view, and the card then offers
 * "Show as table": a chart is a picture, and a screen reader or a phone
 * reads the figures better as rows (dashboard review, 2026-11-03, PR 8).
 * The function renders `<ChartTable>` for the table view.
 */
export function ChartCard({
  title,
  description,
  action,
  footer,
  children,
  className,
  contentClassName,
  titleId,
}: {
  title: ReactNode;
  description?: ReactNode;
  action?: ReactNode;
  footer?: ReactNode;
  children: ReactNode | ((view: ChartView) => ReactNode);
  className?: string;
  contentClassName?: string;
  titleId?: string;
}) {
  const { t } = useT();
  const [view, setView] = useState<ChartView>("chart");
  const switchable = typeof children === "function";

  return (
    <section
      aria-labelledby={titleId}
      className={cn(
        "flex min-w-0 flex-col rounded-xl border border-border bg-card text-card-foreground shadow-xs",
        className,
      )}
    >
      <header className="flex flex-wrap items-start justify-between gap-x-4 gap-y-2 px-5 pt-5">
        <div className="min-w-0">
          <h3
            id={titleId}
            className="text-sm font-semibold leading-6 tracking-tight text-foreground"
          >
            {title}
          </h3>
          {description && (
            <p className="text-xs text-muted-foreground">{description}</p>
          )}
        </div>
        {(action || switchable) && (
          <div className="flex shrink-0 items-center gap-2">
            {action}
            {switchable && (
              <button
                type="button"
                aria-pressed={view === "table"}
                onClick={() =>
                  setView((v) => (v === "chart" ? "table" : "chart"))
                }
                className="rounded-md px-2 py-1 text-xs font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              >
                {view === "chart"
                  ? t("charts.show_as_table", "Show as table")
                  : t("charts.show_as_chart", "Show as chart")}
              </button>
            )}
          </div>
        )}
      </header>
      <div className={cn("min-w-0 flex-1 px-5 pb-5 pt-4", contentClassName)}>
        {typeof children === "function" ? children(view) : children}
      </div>
      {footer && (
        <footer className="border-t border-border px-5 py-3 text-xs text-muted-foreground">
          {footer}
        </footer>
      )}
    </section>
  );
}

export interface ChartTableColumn {
  key: string;
  label: ReactNode;
  /** Right-aligned, tabular figures. */
  numeric?: boolean;
}

/**
 * The figures behind a chart, as a table a screen reader can walk: the
 * caption names the chart, every column has a header, and numbers line up.
 * Tall lists (a month of days) scroll inside the card's own height.
 */
export function ChartTable({
  caption,
  columns,
  rows,
  className,
}: {
  caption: ReactNode;
  columns: ChartTableColumn[];
  rows: Array<Record<string, ReactNode>>;
  className?: string;
}) {
  return (
    <div
      className={cn(
        "max-h-72 overflow-auto rounded-lg border border-border",
        className,
      )}
    >
      <table className="w-full text-sm">
        <caption className="sr-only">{caption}</caption>
        <thead className="sticky top-0 bg-muted text-xs text-muted-foreground">
          <tr>
            {columns.map((column) => (
              <th
                key={column.key}
                scope="col"
                className={cn(
                  "px-3 py-2 font-medium",
                  column.numeric ? "text-right" : "text-left",
                )}
              >
                {column.label}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((row, index) => (
            <tr key={index} className="border-t border-border/60">
              {columns.map((column) => (
                <td
                  key={column.key}
                  className={cn(
                    "px-3 py-1.5",
                    column.numeric
                      ? "text-right tabular-nums text-foreground"
                      : "text-foreground",
                  )}
                >
                  {row[column.key]}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

/**
 * A labelled group of tiles or panels. The heading gives the eye an entry
 * point per group instead of a run of undifferentiated cards.
 */
export function DashboardSection({
  id,
  title,
  description,
  action,
  children,
  className,
}: {
  id: string;
  title: ReactNode;
  description?: ReactNode;
  action?: ReactNode;
  children: ReactNode;
  className?: string;
}) {
  return (
    <section aria-labelledby={id} className={cn("space-y-3", className)}>
      <div className="flex flex-wrap items-end justify-between gap-x-4 gap-y-1">
        <div className="min-w-0">
          <h2
            id={id}
            className="text-base font-semibold tracking-tight text-foreground"
          >
            {title}
          </h2>
          {description && (
            <p className="text-sm text-muted-foreground">{description}</p>
          )}
        </div>
        {action}
      </div>
      {children}
    </section>
  );
}
