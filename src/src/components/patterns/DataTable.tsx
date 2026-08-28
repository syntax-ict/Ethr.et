"use client";

import { Fragment, type ReactNode, useMemo, useState } from "react";
import {
  type ColumnDef,
  type ExpandedState,
  type RowSelectionState,
  type SortingState,
  type VisibilityState,
  flexRender,
  getCoreRowModel,
  getExpandedRowModel,
  useReactTable,
} from "@tanstack/react-table";
import {
  ArrowDown,
  ArrowUp,
  ArrowUpDown,
  ChevronDown,
  ChevronRight,
  Columns3,
  Download,
  Inbox,
} from "lucide-react";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { Checkbox } from "@/components/ui/checkbox";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import {
  DropdownMenu,
  DropdownMenuCheckboxItem,
  DropdownMenuContent,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { EmptyState } from "@/components/shared/empty-state";
import { useLocalStorage } from "@/lib/hooks/useLocalStorage";
import { useT } from "@/lib/i18n/useT";
import { cn } from "@/lib/utils";

export interface DataTableMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  from: number;
  to: number;
}

/** Extra per-column config read via column.columnDef.meta */
export interface DataTableColumnMeta {
  /** Hide this column below the given breakpoint (progressive disclosure on narrow screens) */
  hideBelow?: "sm" | "md" | "lg";
  /** Keep this column visible while the table scrolls horizontally */
  pinned?: "left" | "right";
  className?: string;
}

declare module "@tanstack/react-table" {
  // TS2428 requires an interface augmentation to repeat the original's type
  // parameters *by name*, so these cannot be renamed to the `_` convention or
  // dropped — the augmentation stops compiling either way. They are unused here
  // only because this declaration contributes fields, not generics. The
  // interface itself is empty only in the structural sense (it re-exports
  // DataTableColumnMeta's members via `extends`), which is exactly what
  // no-empty-object-type exists to catch for accidental no-op interfaces —
  // this one is intentional.
  // eslint-disable-next-line @typescript-eslint/no-unused-vars, @typescript-eslint/no-empty-object-type
  interface ColumnMeta<TData, TValue> extends DataTableColumnMeta {}
}

interface DataTableProps<TData> {
  /** Stable, unique id for this table — used as the localStorage key for column visibility */
  tableId: string;
  columns: ColumnDef<TData, unknown>[];
  data: TData[];
  meta?: DataTableMeta;
  getRowId?: (row: TData, index: number) => string;
  isLoading?: boolean;
  isError?: boolean;
  onRetry?: () => void;
  emptyState?: ReactNode;
  skeletonRows?: number;
  /** Controlled single-column sort. Omit to disable sorting entirely. */
  sorting?: SortingState;
  onSortingChange?: (sorting: SortingState) => void;
  /**
   * Controlled column visibility, for callers that need to read or apply it
   * externally (e.g. saved views). Omit both this and `onColumnVisibilityChange`
   * to keep the table's own `datatable:${tableId}:visibility` localStorage default.
   */
  columnVisibility?: VisibilityState;
  onColumnVisibilityChange?: (visibility: VisibilityState) => void;
  onPageChange?: (page: number) => void;
  /** Rendered in the toolbar row, alongside Export CSV / Columns */
  toolbarExtra?: ReactNode;
  selectable?: boolean;
  onSelectionChange?: (selectedIds: string[]) => void;
  /** Rendered above the table when at least one row is selected */
  bulkActions?: (selectedIds: string[]) => ReactNode;
  /** Render an expandable detail panel below a row; presence of this prop enables the expand toggle column */
  renderSubRow?: (row: TData) => ReactNode;
  getRowCanExpand?: (row: TData) => boolean;
  /**
   * Maps a row to a flat, CSV-serializable object (object keys become the
   * header row). Presence of this prop enables an "Export CSV" button that
   * downloads the currently loaded page — a lightweight client-side
   * convenience for what's on screen, not the full server-side export/report
   * pipeline (see features/reports) which handles complete, filtered,
   * multi-page exports. Callers supply this explicitly rather than the table
   * inferring it from column definitions, since most columns in this codebase
   * render from `row.original` directly instead of an accessorKey/accessorFn.
   */
  getExportRow?: (
    row: TData,
  ) => Record<string, string | number | null | undefined>;
  exportFilename?: string;
  className?: string;
}

function csvCell(value: unknown): string {
  const text = value == null ? "" : String(value);
  return /[",\n]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
}

function columnMeta(def: { meta?: unknown }): DataTableColumnMeta {
  return (def.meta as DataTableColumnMeta | undefined) ?? {};
}

function hideBelowClass(hideBelow?: "sm" | "md" | "lg"): string | undefined {
  if (!hideBelow) return undefined;
  return `hidden ${hideBelow}:table-cell`;
}

function pinnedClass(pinned?: "left" | "right"): string | undefined {
  if (!pinned) return undefined;
  return cn(
    "sticky z-10 bg-background",
    pinned === "left" ? "left-0" : "right-0",
  );
}

export function DataTable<TData>({
  tableId,
  columns,
  data,
  meta,
  getRowId,
  isLoading = false,
  isError = false,
  onRetry,
  emptyState,
  skeletonRows = 8,
  sorting,
  onSortingChange,
  columnVisibility: controlledColumnVisibility,
  onColumnVisibilityChange,
  onPageChange,
  toolbarExtra,
  selectable = false,
  onSelectionChange,
  bulkActions,
  renderSubRow,
  getRowCanExpand,
  getExportRow,
  exportFilename = "export",
  className,
}: DataTableProps<TData>) {
  const { t } = useT();
  const [internalColumnVisibility, setInternalColumnVisibility] =
    useLocalStorage<VisibilityState>(`datatable:${tableId}:visibility`, {});
  const columnVisibility =
    controlledColumnVisibility ?? internalColumnVisibility;
  const [rowSelection, setRowSelection] = useState<RowSelectionState>({});
  const [expanded, setExpanded] = useState<ExpandedState>({});

  function toggleSort(columnId: string) {
    if (!onSortingChange) return;
    const current = sorting?.[0];
    if (!current || current.id !== columnId) {
      onSortingChange([{ id: columnId, desc: false }]);
    } else if (!current.desc) {
      onSortingChange([{ id: columnId, desc: true }]);
    } else {
      onSortingChange([]);
    }
  }

  const tableColumns = useMemo<ColumnDef<TData, unknown>[]>(() => {
    let cols = columns;
    if (renderSubRow) {
      const expandColumn: ColumnDef<TData, unknown> = {
        id: "__expand",
        header: () => null,
        cell: ({ row }) =>
          row.getCanExpand() ? (
            <button
              type="button"
              className="flex h-5 w-5 items-center justify-center rounded hover:bg-muted"
              onClick={row.getToggleExpandedHandler()}
              aria-label={
                row.getIsExpanded()
                  ? t("table.collapse_row", "Collapse row")
                  : t("table.expand_row", "Expand row")
              }
            >
              {row.getIsExpanded() ? (
                <ChevronDown className="h-3.5 w-3.5" />
              ) : (
                <ChevronRight className="h-3.5 w-3.5" />
              )}
            </button>
          ) : null,
        enableSorting: false,
        enableHiding: false,
      };
      cols = [expandColumn, ...cols];
    }
    if (selectable) {
      const selectColumn: ColumnDef<TData, unknown> = {
        id: "__select",
        header: ({ table }) => (
          <Checkbox
            checked={
              table.getIsAllPageRowsSelected() ||
              (table.getIsSomePageRowsSelected() && "indeterminate")
            }
            onCheckedChange={(value) =>
              table.toggleAllPageRowsSelected(!!value)
            }
            aria-label={t("table.select_all_rows", "Select all rows")}
          />
        ),
        cell: ({ row }) => (
          <Checkbox
            checked={row.getIsSelected()}
            onCheckedChange={(value) => row.toggleSelected(!!value)}
            aria-label={t("table.select_row", "Select row")}
          />
        ),
        enableSorting: false,
        enableHiding: false,
      };
      cols = [selectColumn, ...cols];
    }
    return cols;
  }, [columns, selectable, renderSubRow, t]);

  const table = useReactTable({
    data,
    columns: tableColumns,
    state: {
      sorting: sorting ?? [],
      columnVisibility,
      rowSelection,
      expanded,
    },
    onColumnVisibilityChange: (updater) => {
      const next =
        typeof updater === "function" ? updater(columnVisibility) : updater;
      if (onColumnVisibilityChange) {
        onColumnVisibilityChange(next);
      } else {
        setInternalColumnVisibility(next);
      }
    },
    onRowSelectionChange: (updater) => {
      setRowSelection((prev) => {
        const next = typeof updater === "function" ? updater(prev) : updater;
        onSelectionChange?.(Object.keys(next));
        return next;
      });
    },
    onExpandedChange: setExpanded,
    getRowCanExpand: getRowCanExpand
      ? (row) => getRowCanExpand(row.original)
      : () => !!renderSubRow,
    getRowId: getRowId ? (row, index) => getRowId(row, index) : undefined,
    getCoreRowModel: getCoreRowModel(),
    getExpandedRowModel: getExpandedRowModel(),
    manualSorting: true,
    manualPagination: true,
    enableRowSelection: selectable,
    enableSorting: !!onSortingChange,
  });

  const selectedIds = Object.keys(rowSelection);
  const visibleColumnCount = table.getVisibleFlatColumns().length;

  function handleExport() {
    if (!getExportRow) return;
    const rows = table
      .getRowModel()
      .rows.map((row) => getExportRow(row.original));
    if (rows.length === 0) return;

    const headers = Object.keys(rows[0]);
    const lines = [
      headers.map(csvCell).join(","),
      ...rows.map((row) => headers.map((key) => csvCell(row[key])).join(",")),
    ];

    const blob = new Blob([lines.join("\n")], {
      type: "text/csv;charset=utf-8;",
    });
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.href = url;
    link.download = `${exportFilename}.csv`;
    link.click();
    URL.revokeObjectURL(url);
  }

  if (isError) {
    return (
      <div className="flex flex-col items-center justify-center rounded-xl border border-border/60 py-16 text-center">
        <p className="text-sm font-medium text-foreground">
          Something went wrong loading this data.
        </p>
        {onRetry && (
          <Button
            variant="outline"
            size="sm"
            className="mt-4"
            onClick={onRetry}
          >
            Try Again
          </Button>
        )}
      </div>
    );
  }

  if (!isLoading && data.length === 0) {
    return (
      emptyState ?? (
        <EmptyState
          icon={Inbox}
          title={t("common.no_results", "No results found")}
        />
      )
    );
  }

  return (
    <div className={cn("space-y-3", className)}>
      <div className="flex items-center justify-between gap-2">
        <div className="flex-1">
          {selectable && selectedIds.length > 0 && bulkActions?.(selectedIds)}
        </div>
        {toolbarExtra}
        {getExportRow && (
          <Button variant="outline" size="sm" onClick={handleExport}>
            <Download className="mr-2 h-3.5 w-3.5" />
            {t("table.export_csv", "Export CSV")}
          </Button>
        )}
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button variant="outline" size="sm">
              <Columns3 className="mr-2 h-3.5 w-3.5" />
              {t("table.columns", "Columns")}
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            {table
              .getAllColumns()
              .filter((column) => column.getCanHide())
              .map((column) => (
                <DropdownMenuCheckboxItem
                  key={column.id}
                  checked={column.getIsVisible()}
                  onCheckedChange={(value) => column.toggleVisibility(!!value)}
                  onSelect={(e) => e.preventDefault()}
                >
                  {typeof column.columnDef.header === "string"
                    ? column.columnDef.header
                    : column.id}
                </DropdownMenuCheckboxItem>
              ))}
          </DropdownMenuContent>
        </DropdownMenu>
      </div>

      <div className="rounded-xl border border-border/60 overflow-hidden">
        <Table>
          <TableHeader className="sticky top-0 z-10 bg-muted/40">
            {table.getHeaderGroups().map((headerGroup) => (
              <TableRow key={headerGroup.id} className="hover:bg-transparent">
                {headerGroup.headers.map((header) => {
                  const cm = columnMeta(header.column.columnDef);
                  const canSort = header.column.getCanSort();
                  const sortDir = header.column.getIsSorted();
                  return (
                    <TableHead
                      key={header.id}
                      className={cn(
                        hideBelowClass(cm.hideBelow),
                        pinnedClass(cm.pinned),
                        cm.className,
                      )}
                    >
                      {header.isPlaceholder ? null : canSort ? (
                        <button
                          type="button"
                          className="flex items-center gap-1 hover:text-foreground"
                          onClick={() => toggleSort(header.column.id)}
                        >
                          {flexRender(
                            header.column.columnDef.header,
                            header.getContext(),
                          )}
                          {sortDir === "asc" ? (
                            <ArrowUp className="h-3 w-3" />
                          ) : sortDir === "desc" ? (
                            <ArrowDown className="h-3 w-3" />
                          ) : (
                            <ArrowUpDown className="h-3 w-3 opacity-40" />
                          )}
                        </button>
                      ) : (
                        flexRender(
                          header.column.columnDef.header,
                          header.getContext(),
                        )
                      )}
                    </TableHead>
                  );
                })}
              </TableRow>
            ))}
          </TableHeader>
          <TableBody>
            {isLoading
              ? Array.from({ length: skeletonRows }).map((_, i) => (
                  <TableRow
                    key={`skeleton-${i}`}
                    className="hover:bg-transparent"
                  >
                    {Array.from({ length: visibleColumnCount }).map((_, j) => (
                      <TableCell key={j}>
                        <Skeleton className="h-4 w-full max-w-[160px]" />
                      </TableCell>
                    ))}
                  </TableRow>
                ))
              : table.getRowModel().rows.map((row) => (
                  <Fragment key={row.id}>
                    <TableRow
                      data-state={row.getIsSelected() ? "selected" : undefined}
                    >
                      {row.getVisibleCells().map((cell) => {
                        const cm = columnMeta(cell.column.columnDef);
                        return (
                          <TableCell
                            key={cell.id}
                            className={cn(
                              hideBelowClass(cm.hideBelow),
                              pinnedClass(cm.pinned),
                              cm.className,
                            )}
                          >
                            {flexRender(
                              cell.column.columnDef.cell,
                              cell.getContext(),
                            )}
                          </TableCell>
                        );
                      })}
                    </TableRow>
                    {renderSubRow && row.getIsExpanded() && (
                      <TableRow className="hover:bg-transparent">
                        <TableCell colSpan={visibleColumnCount}>
                          {renderSubRow(row.original)}
                        </TableCell>
                      </TableRow>
                    )}
                  </Fragment>
                ))}
          </TableBody>
        </Table>
      </div>

      {meta && meta.last_page > 1 && onPageChange && (
        <div className="flex items-center justify-between">
          <p className="text-sm text-muted-foreground">
            {t("table.showing", "Showing")} {meta.from}–{meta.to}{" "}
            {t("table.of", "of")} {meta.total}
          </p>
          <div className="flex gap-2">
            <Button
              variant="outline"
              size="sm"
              disabled={meta.current_page <= 1}
              onClick={() => onPageChange(meta.current_page - 1)}
            >
              {t("table.previous", "Previous")}
            </Button>
            <Button
              variant="outline"
              size="sm"
              disabled={meta.current_page >= meta.last_page}
              onClick={() => onPageChange(meta.current_page + 1)}
            >
              {t("common.next", "Next")}
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}
