"use client";

import { type ReactNode, useMemo, useState } from "react";
import {
  type ColumnDef,
  type RowSelectionState,
  type SortingState,
  type VisibilityState,
  flexRender,
  getCoreRowModel,
  useReactTable,
} from "@tanstack/react-table";
import { ArrowDown, ArrowUp, ArrowUpDown, Columns3, Inbox } from "lucide-react";
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
  className?: string;
}

declare module "@tanstack/react-table" {
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
  onPageChange?: (page: number) => void;
  selectable?: boolean;
  onSelectionChange?: (selectedIds: string[]) => void;
  /** Rendered above the table when at least one row is selected */
  bulkActions?: (selectedIds: string[]) => ReactNode;
  className?: string;
}

function columnMeta(def: { meta?: unknown }): DataTableColumnMeta {
  return (def.meta as DataTableColumnMeta | undefined) ?? {};
}

function hideBelowClass(hideBelow?: "sm" | "md" | "lg"): string | undefined {
  if (!hideBelow) return undefined;
  return `hidden ${hideBelow}:table-cell`;
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
  onPageChange,
  selectable = false,
  onSelectionChange,
  bulkActions,
  className,
}: DataTableProps<TData>) {
  const { t } = useT();
  const [columnVisibility, setColumnVisibility] =
    useLocalStorage<VisibilityState>(`datatable:${tableId}:visibility`, {});
  const [rowSelection, setRowSelection] = useState<RowSelectionState>({});

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
    if (!selectable) return columns;
    const selectColumn: ColumnDef<TData, unknown> = {
      id: "__select",
      header: ({ table }) => (
        <Checkbox
          checked={
            table.getIsAllPageRowsSelected() ||
            (table.getIsSomePageRowsSelected() && "indeterminate")
          }
          onCheckedChange={(value) => table.toggleAllPageRowsSelected(!!value)}
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
    return [selectColumn, ...columns];
  }, [columns, selectable, t]);

  const table = useReactTable({
    data,
    columns: tableColumns,
    state: {
      sorting: sorting ?? [],
      columnVisibility,
      rowSelection,
    },
    onColumnVisibilityChange: (updater) =>
      setColumnVisibility((prev) =>
        typeof updater === "function" ? updater(prev) : updater,
      ),
    onRowSelectionChange: (updater) => {
      setRowSelection((prev) => {
        const next = typeof updater === "function" ? updater(prev) : updater;
        onSelectionChange?.(Object.keys(next));
        return next;
      });
    },
    getRowId: getRowId ? (row, index) => getRowId(row, index) : undefined,
    getCoreRowModel: getCoreRowModel(),
    manualSorting: true,
    manualPagination: true,
    enableRowSelection: selectable,
    enableSorting: !!onSortingChange,
  });

  const selectedIds = Object.keys(rowSelection);
  const visibleColumnCount = table.getVisibleFlatColumns().length;

  if (isError) {
    return (
      <div className="flex flex-col items-center justify-center rounded-lg border py-16 text-center">
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

      <div className="rounded-lg border">
        <Table>
          <TableHeader className="sticky top-0 z-10 bg-muted/50">
            {table.getHeaderGroups().map((headerGroup) => (
              <TableRow key={headerGroup.id} className="hover:bg-transparent">
                {headerGroup.headers.map((header) => {
                  const cm = columnMeta(header.column.columnDef);
                  const canSort = header.column.getCanSort();
                  const sortDir = header.column.getIsSorted();
                  return (
                    <TableHead
                      key={header.id}
                      className={cn(hideBelowClass(cm.hideBelow), cm.className)}
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
                  <TableRow
                    key={row.id}
                    data-state={row.getIsSelected() ? "selected" : undefined}
                  >
                    {row.getVisibleCells().map((cell) => {
                      const cm = columnMeta(cell.column.columnDef);
                      return (
                        <TableCell
                          key={cell.id}
                          className={cn(
                            hideBelowClass(cm.hideBelow),
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
