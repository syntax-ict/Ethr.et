import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import type { ColumnDef } from "@tanstack/react-table";
import { DataTable, type DataTableMeta } from "@/components/patterns/DataTable";

interface Row {
  id: string;
  name: string;
}

const columns: ColumnDef<Row, unknown>[] = [
  { accessorKey: "name", header: "Name" },
];

const rows: Row[] = [
  { id: "1", name: "Abebe Kebede" },
  { id: "2", name: "Sara Tesfaye" },
];

const meta: DataTableMeta = {
  current_page: 1,
  last_page: 3,
  per_page: 25,
  total: 60,
  from: 1,
  to: 25,
};

describe("DataTable", () => {
  beforeEach(() => {
    localStorage.clear();
  });

  it("renders skeleton rows while loading, not the row data", () => {
    render(
      <DataTable
        tableId="test-loading"
        columns={columns}
        data={[]}
        isLoading
      />,
    );
    expect(screen.queryByText("Abebe Kebede")).not.toBeInTheDocument();
    expect(screen.getByText("Name")).toBeInTheDocument();
  });

  it("shows the default empty state when there is no data", () => {
    render(<DataTable tableId="test-empty" columns={columns} data={[]} />);
    expect(screen.getByText("No results found")).toBeInTheDocument();
  });

  it("renders a custom empty state when provided", () => {
    render(
      <DataTable
        tableId="test-empty-custom"
        columns={columns}
        data={[]}
        emptyState={<div>Nothing here</div>}
      />,
    );
    expect(screen.getByText("Nothing here")).toBeInTheDocument();
  });

  it("renders row data", () => {
    render(<DataTable tableId="test-data" columns={columns} data={rows} />);
    expect(screen.getByText("Abebe Kebede")).toBeInTheDocument();
    expect(screen.getByText("Sara Tesfaye")).toBeInTheDocument();
  });

  it("shows an error state with a working retry button", async () => {
    const onRetry = vi.fn();
    render(
      <DataTable
        tableId="test-error"
        columns={columns}
        data={[]}
        isError
        onRetry={onRetry}
      />,
    );
    await userEvent.click(screen.getByText("Try Again"));
    expect(onRetry).toHaveBeenCalledTimes(1);
  });

  it("sorts ascending on first header click", async () => {
    const onSortingChange = vi.fn();
    render(
      <DataTable
        tableId="test-sort"
        columns={columns}
        data={rows}
        sorting={[]}
        onSortingChange={onSortingChange}
      />,
    );
    await userEvent.click(screen.getByText("Name"));
    expect(onSortingChange).toHaveBeenCalledWith([{ id: "name", desc: false }]);
  });

  it("cycles asc -> desc -> cleared across re-renders", async () => {
    const onSortingChange = vi.fn();
    const { rerender } = render(
      <DataTable
        tableId="test-sort-cycle"
        columns={columns}
        data={rows}
        sorting={[{ id: "name", desc: false }]}
        onSortingChange={onSortingChange}
      />,
    );
    await userEvent.click(screen.getByText("Name"));
    expect(onSortingChange).toHaveBeenLastCalledWith([
      { id: "name", desc: true },
    ]);

    rerender(
      <DataTable
        tableId="test-sort-cycle"
        columns={columns}
        data={rows}
        sorting={[{ id: "name", desc: true }]}
        onSortingChange={onSortingChange}
      />,
    );
    await userEvent.click(screen.getByText("Name"));
    expect(onSortingChange).toHaveBeenLastCalledWith([]);
  });

  it("renders pagination and calls onPageChange", async () => {
    const onPageChange = vi.fn();
    render(
      <DataTable
        tableId="test-page"
        columns={columns}
        data={rows}
        meta={meta}
        onPageChange={onPageChange}
      />,
    );
    expect(screen.getByText(/Showing/)).toBeInTheDocument();
    expect(screen.getByText("Previous")).toBeDisabled();
    await userEvent.click(screen.getByText("Next"));
    expect(onPageChange).toHaveBeenCalledWith(2);
  });

  it("persists column visibility toggles to localStorage", async () => {
    const twoColumns: ColumnDef<Row, unknown>[] = [
      { accessorKey: "name", header: "Name" },
      { accessorKey: "id", header: "ID" },
    ];
    render(
      <DataTable tableId="test-visibility" columns={twoColumns} data={rows} />,
    );

    await userEvent.click(screen.getByRole("button", { name: /columns/i }));
    await userEvent.click(screen.getByRole("menuitemcheckbox", { name: "ID" }));

    expect(
      JSON.parse(
        localStorage.getItem("datatable:test-visibility:visibility") ?? "{}",
      ),
    ).toEqual({ id: false });
  });
});
