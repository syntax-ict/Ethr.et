"use client";

import { Fragment, type ReactNode } from "react";
import { Edit, Trash2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { useT } from "@/lib/i18n/useT";
import { useIsPhone } from "@/lib/hooks/useMediaQuery";

/**
 * The shared primitive for the many small, non-paginated tables across the app
 * (settings lists, org structure, config screens). For large, sortable,
 * selectable data grids use `DataTable` instead; this is deliberately lighter.
 *
 * It exists to end the pattern of every screen hand-rolling its own `<table>`
 * with subtly different markup. Accessibility is built in: `scope="col"`
 * headers and accessible names on the icon-only row actions — both of which the
 * hand-rolled copies routinely omitted.
 */
export type ColumnAlign = "left" | "right" | "center";

const ALIGN_CLASS: Record<ColumnAlign, string> = {
  left: "text-left",
  right: "text-right",
  center: "text-center",
};

export interface SimpleTableRow {
  key: string;
  cells: ReactNode[];
  /** Convenience edit/delete actions; rendered in a trailing actions column. */
  onEdit?: () => void;
  onDelete?: () => void;
  /** Fully custom action content, if edit/delete aren't the right verbs. */
  actions?: ReactNode;
  /** Extra classes on the row — e.g. a background tint for an error/flagged row. */
  className?: string;
  /** Makes the whole row clickable (e.g. navigate to a detail page). */
  onClick?: () => void;
}

export function TableRowActions({
  onEdit,
  onDelete,
}: {
  onEdit?: () => void;
  onDelete?: () => void;
}) {
  const { t } = useT();
  return (
    <div className="flex justify-end gap-1">
      {onEdit && (
        <Button
          size="sm"
          variant="ghost"
          className="h-7 w-7 p-0"
          onClick={onEdit}
        >
          <Edit className="h-3.5 w-3.5" />
          <span className="sr-only">{t("common.edit", "Edit")}</span>
        </Button>
      )}
      {onDelete && (
        <Button
          size="sm"
          variant="ghost"
          className="h-7 w-7 p-0"
          onClick={onDelete}
        >
          <Trash2 className="h-3.5 w-3.5 text-destructive" />
          <span className="sr-only">{t("common.delete", "Delete")}</span>
        </Button>
      )}
    </div>
  );
}

export function SimpleTable({
  headers,
  rows,
  align,
  colClassName,
  caption,
  maxHeight,
  footerCells,
  mobileCards = false,
}: {
  /** Column headers. Usually plain text, but a matrix-style table (e.g. a
   *  preference grid) can pass richer content — an icon + label stacked
   *  vertically, a badge, etc. */
  headers: ReactNode[];
  rows: SimpleTableRow[];
  /** Per-column text alignment (defaults to left). The actions column is always right. */
  align?: ColumnAlign[];
  /** Per-column extra classes on both header and cells — e.g. `hidden sm:table-cell`. */
  colClassName?: string[];
  /** Screen-reader label for the table; visually hidden. */
  caption?: string;
  /** Bounds the table to a scrollable height with a sticky header — for large,
   *  ad-hoc result grids (e.g. a generated report) where the row count isn't
   *  paginated. Accepts any CSS height, e.g. `"calc(100vh-280px)"`. */
  maxHeight?: string;
  /** A trailing `<tfoot>` row — e.g. totals for a financial/journal table.
   *  One cell per column (any actions column is left blank automatically). */
  footerCells?: ReactNode[];
  /**
   * Below the `sm` breakpoint, draw each row as a card instead of a table row:
   * the first cell is the card's title and every other cell is a labelled
   * line, with its header as the label. For lists people read on a phone,
   * where a wide table scrolled sideways (FRONTEND_REDESIGN_PLAN.md, problem
   * 4). Leave it off for matrix-style grids, which read better as a table.
   */
  mobileCards?: boolean;
}) {
  const { t } = useT();
  const isPhone = useIsPhone();
  const hasActions = rows.some((r) => r.onEdit || r.onDelete || r.actions);
  const alignClass = (i: number) => ALIGN_CLASS[align?.[i] ?? "left"];
  const colClass = (i: number) => colClassName?.[i] ?? "";

  const table = (
    <div
      // `relative` is load-bearing, not cosmetic. Tailwind's `sr-only` is
      // `position: absolute`, and an absolutely-positioned element is clipped
      // by a scroll container only if that container is its containing block.
      // Without `relative` here, the `sr-only` labels inside the row action
      // buttons resolved against `<html>` instead — so on a table wider than
      // the viewport they sat at the table's full width (x=467 on a 375px
      // screen), escaped this scroller, and gave the whole *page* a horizontal
      // scrollbar. `ui/table.tsx` already gets this right.
      className="relative overflow-x-auto"
      style={maxHeight ? { maxHeight, overflowY: "auto" } : undefined}
    >
      <table className="w-full">
        {caption && <caption className="sr-only">{caption}</caption>}
        <thead className={maxHeight ? "sticky top-0 z-10 bg-muted" : undefined}>
          <tr className="border-b bg-muted/50">
            {headers.map((h, i) => (
              <th
                key={i}
                scope="col"
                className={`px-4 py-3 text-xs font-medium text-muted-foreground ${alignClass(i)} ${colClass(i)}`}
              >
                {h}
              </th>
            ))}
            {hasActions && (
              <th
                scope="col"
                className="px-4 py-3 text-right text-xs font-medium text-muted-foreground"
              >
                {t("common.actions", "Actions")}
              </th>
            )}
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr
              key={row.key}
              onClick={row.onClick}
              // A clickable row needs a keyboard path too — the hand-rolled
              // tables this replaces were mouse-only.
              tabIndex={row.onClick ? 0 : undefined}
              role={row.onClick ? "button" : undefined}
              onKeyDown={
                row.onClick
                  ? (e) => {
                      if (e.key === "Enter" || e.key === " ") {
                        e.preventDefault();
                        row.onClick?.();
                      }
                    }
                  : undefined
              }
              className={`border-b last:border-0 hover:bg-muted/30 ${row.onClick ? "cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset" : ""} ${row.className ?? ""}`}
            >
              {row.cells.map((c, i) => (
                <td
                  key={i}
                  className={`px-4 py-3 text-sm text-foreground ${alignClass(i)} ${colClass(i)}`}
                >
                  {c}
                </td>
              ))}
              {hasActions && (
                <td className="px-4 py-3 text-right">
                  {row.actions ?? (
                    <TableRowActions
                      onEdit={row.onEdit}
                      onDelete={row.onDelete}
                    />
                  )}
                </td>
              )}
            </tr>
          ))}
        </tbody>
        {footerCells && (
          <tfoot className="border-t bg-muted/50 font-medium">
            <tr>
              {footerCells.map((c, i) => (
                <td
                  key={i}
                  className={`px-4 py-3 text-sm text-foreground ${alignClass(i)} ${colClass(i)}`}
                >
                  {c}
                </td>
              ))}
              {hasActions && <td />}
            </tr>
          </tfoot>
        )}
      </table>
    </div>
  );

  // One layout at a time, chosen by the window width. These lists render after
  // their data arrives in the browser, so the width is already known and no
  // table flashes before the cards.
  if (!mobileCards || !isPhone) return table;

  const card = (
    cells: ReactNode[],
    key: string,
    extra?: Partial<SimpleTableRow>,
  ) => (
    <li
      key={key}
      onClick={extra?.onClick}
      tabIndex={extra?.onClick ? 0 : undefined}
      role={extra?.onClick ? "button" : undefined}
      onKeyDown={
        extra?.onClick
          ? (e) => {
              if (e.key === "Enter" || e.key === " ") {
                e.preventDefault();
                extra.onClick?.();
              }
            }
          : undefined
      }
      className={`rounded-lg border border-border bg-card p-3 ${extra?.onClick ? "cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring" : ""} ${extra?.className ?? ""}`}
    >
      <div className="text-sm font-medium text-foreground">{cells[0]}</div>
      {cells.length > 1 && (
        <dl className="mt-2 grid grid-cols-[minmax(0,auto)_minmax(0,1fr)] gap-x-3 gap-y-1 text-sm">
          {cells.slice(1).map((c, i) => (
            <Fragment key={i}>
              <dt className="text-muted-foreground">{headers[i + 1]}</dt>
              <dd className="min-w-0 break-words text-foreground">{c}</dd>
            </Fragment>
          ))}
        </dl>
      )}
      {extra && (extra.actions || extra.onEdit || extra.onDelete) && (
        <div className="mt-2 flex justify-end border-t border-border pt-2">
          {extra.actions ?? (
            <TableRowActions onEdit={extra.onEdit} onDelete={extra.onDelete} />
          )}
        </div>
      )}
    </li>
  );

  return (
    <ul className="space-y-2" aria-label={caption}>
      {rows.map((row) => card(row.cells, row.key, row))}
      {footerCells &&
        card(footerCells, "__footer", { className: "bg-muted/50" })}
    </ul>
  );
}
