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
    localStorage.setItem("locale", "en");
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

  it("expands a row to reveal its sub-row content and collapses it again", async () => {
    render(
      <DataTable
        tableId="test-expand"
        columns={columns}
        data={rows}
        renderSubRow={(row) => <div>Details for {row.name}</div>}
      />,
    );

    expect(
      screen.queryByText("Details for Abebe Kebede"),
    ).not.toBeInTheDocument();

    const [firstToggle] = screen.getAllByRole("button", {
      name: /expand row/i,
    });
    await userEvent.click(firstToggle);

    expect(screen.getByText("Details for Abebe Kebede")).toBeInTheDocument();
    expect(
      screen.queryByText("Details for Sara Tesfaye"),
    ).not.toBeInTheDocument();

    await userEvent.click(
      screen.getByRole("button", { name: /collapse row/i }),
    );
    expect(
      screen.queryByText("Details for Abebe Kebede"),
    ).not.toBeInTheDocument();
  });

  it("only shows the expand toggle for rows where getRowCanExpand returns true", () => {
    render(
      <DataTable
        tableId="test-expand-conditional"
        columns={columns}
        data={rows}
        renderSubRow={(row) => <div>Details for {row.name}</div>}
        getRowCanExpand={(row) => row.id === "1"}
      />,
    );

    expect(screen.getAllByRole("button", { name: /expand row/i })).toHaveLength(
      1,
    );
  });

  it("exports the currently loaded rows as a downloaded CSV file", async () => {
    const clickSpy = vi.fn();
    // jsdom doesn't implement these, so stub them directly rather than spyOn
    // (which requires the property to already exist on the object).
    URL.createObjectURL = vi.fn().mockReturnValue("blob:mock-url");
    URL.revokeObjectURL = vi.fn();
    const createObjectURL = vi.mocked(URL.createObjectURL);
    const revokeObjectURL = vi.mocked(URL.revokeObjectURL);
    const createElementSpy = vi.spyOn(document, "createElement");

    render(
      <DataTable
        tableId="test-export"
        columns={columns}
        data={rows}
        getExportRow={(row) => ({ ID: row.id, Name: row.name })}
        exportFilename="employees"
      />,
    );

    const link = document.createElement("a");
    link.click = clickSpy;
    createElementSpy.mockReturnValueOnce(link);

    await userEvent.click(screen.getByRole("button", { name: /export csv/i }));

    expect(createObjectURL).toHaveBeenCalledTimes(1);
    const [blob] = createObjectURL.mock.calls[0];
    expect((blob as Blob).type).toBe("text/csv;charset=utf-8;");
    expect(link.download).toBe("employees.csv");
    expect(clickSpy).toHaveBeenCalledTimes(1);
    expect(revokeObjectURL).toHaveBeenCalledWith("blob:mock-url");

    createObjectURL.mockRestore();
    revokeObjectURL.mockRestore();
    createElementSpy.mockRestore();
  });

  it("does not render an export button when getExportRow is not provided", () => {
    render(
      <DataTable tableId="test-no-export" columns={columns} data={rows} />,
    );
    expect(
      screen.queryByRole("button", { name: /export csv/i }),
    ).not.toBeInTheDocument();
  });

  it("renders Amharic text without truncation or encoding issues", () => {
    const amharicRows: Row[] = [
      { id: "1", name: "አበበ ከበደ" },
      { id: "2", name: "ሳራ ተስፋዬ" },
    ];
    render(
      <DataTable tableId="test-amharic" columns={columns} data={amharicRows} />,
    );
    expect(screen.getByText("አበበ ከበደ")).toBeInTheDocument();
    expect(screen.getByText("ሳራ ተስፋዬ")).toBeInTheDocument();
  });

  it("applies sticky positioning classes to pinned columns", () => {
    const pinnedColumns: ColumnDef<Row, unknown>[] = [
      {
        accessorKey: "name",
        header: "Name",
        meta: { pinned: "left" },
      },
      { accessorKey: "id", header: "ID" },
    ];
    render(
      <DataTable tableId="test-pin" columns={pinnedColumns} data={rows} />,
    );

    const nameHeader = screen.getByText("Name").closest("th");
    expect(nameHeader).toHaveClass("sticky", "left-0");

    const idHeader = screen.getByText("ID").closest("th");
    expect(idHeader).not.toHaveClass("sticky");
  });
});
