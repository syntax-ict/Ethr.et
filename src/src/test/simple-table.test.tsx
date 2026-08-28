import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { SimpleTable } from "@/components/shared/simple-table";

describe("SimpleTable", () => {
  it("renders headers as column scopes and one row per entry", () => {
    render(
      <SimpleTable
        headers={["Name", "Code"]}
        rows={[
          { key: "a", cells: ["Engineering", "ENG"] },
          { key: "b", cells: ["Finance", "FIN"] },
        ]}
      />,
    );

    const headers = screen.getAllByRole("columnheader");
    expect(headers.map((h) => h.textContent)).toEqual(["Name", "Code"]);
    expect(headers[0]).toHaveAttribute("scope", "col");
    expect(screen.getByText("Engineering")).toBeInTheDocument();
    expect(screen.getByText("Finance")).toBeInTheDocument();
  });

  it("applies per-column alignment to header and cells", () => {
    render(
      <SimpleTable
        headers={["Name", "Days"]}
        align={["left", "right"]}
        rows={[{ key: "a", cells: ["Annual", "20"] }]}
      />,
    );
    const [nameHeader, daysHeader] = screen.getAllByRole("columnheader");
    expect(nameHeader.className).toContain("text-left");
    expect(daysHeader.className).toContain("text-right");
    expect(screen.getByText("20").className).toContain("text-right");
  });

  it("applies per-column extra classes to header and cells", () => {
    render(
      <SimpleTable
        headers={["Name", "Created"]}
        colClassName={["", "hidden md:table-cell"]}
        rows={[{ key: "a", cells: ["Key", "2026-01-01"] }]}
      />,
    );
    const [, createdHeader] = screen.getAllByRole("columnheader");
    expect(createdHeader.className).toContain("hidden md:table-cell");
    expect(screen.getByText("2026-01-01").className).toContain(
      "hidden md:table-cell",
    );
  });

  it("applies a per-row className for flagged/error rows", () => {
    render(
      <SimpleTable
        headers={["Name"]}
        rows={[
          { key: "a", cells: ["Bad row"], className: "bg-destructive-soft" },
          { key: "b", cells: ["Good row"] },
        ]}
      />,
    );
    const badRow = screen.getByText("Bad row").closest("tr");
    const goodRow = screen.getByText("Good row").closest("tr");
    expect(badRow?.className).toContain("bg-destructive-soft");
    expect(goodRow?.className).not.toContain("bg-destructive-soft");
  });

  it("makes a row clickable and keyboard-activatable when onClick is given", async () => {
    const onClick = vi.fn();
    const user = userEvent.setup();
    render(
      <SimpleTable
        headers={["Name"]}
        rows={[{ key: "a", cells: ["Acme Corp"], onClick }]}
      />,
    );

    const row = screen.getByRole("button", { name: /acme corp/i });
    expect(row.tagName).toBe("TR");

    await user.click(row);
    expect(onClick).toHaveBeenCalledTimes(1);

    row.focus();
    await user.keyboard("{Enter}");
    expect(onClick).toHaveBeenCalledTimes(2);
  });

  it("sticks the header and bounds the height when maxHeight is given", () => {
    render(
      <SimpleTable
        headers={["Name"]}
        rows={[{ key: "a", cells: ["X"] }]}
        maxHeight="200px"
      />,
    );
    const wrapper = screen.getByText("X").closest("table")?.parentElement;
    expect(wrapper).toHaveStyle({ maxHeight: "200px", overflowY: "auto" });
    const thead = screen.getByText("Name").closest("thead");
    expect(thead?.className).toContain("sticky");
  });

  it("renders a footer row when footerCells is given", () => {
    render(
      <SimpleTable
        headers={["Account", "Debit", "Credit"]}
        rows={[{ key: "a", cells: ["Cash", "100.00", "—"] }]}
        footerCells={["Totals", "100.00", "0.00"]}
      />,
    );
    const foot = screen.getByText("Totals").closest("tfoot");
    expect(foot).not.toBeNull();
    expect(foot).toHaveTextContent("Totals");
    expect(foot).toHaveTextContent("100.00");
  });

  it("omits the actions column when no row has actions", () => {
    render(
      <SimpleTable headers={["Name"]} rows={[{ key: "a", cells: ["X"] }]} />,
    );
    // Only the single data column header — no trailing Actions header.
    expect(screen.getAllByRole("columnheader")).toHaveLength(1);
    expect(screen.queryByText("Actions")).not.toBeInTheDocument();
  });

  it("renders accessible edit/delete actions that fire their handlers", async () => {
    const onEdit = vi.fn();
    const onDelete = vi.fn();
    const user = userEvent.setup();

    render(
      <SimpleTable
        headers={["Name"]}
        rows={[{ key: "a", cells: ["X"], onEdit, onDelete }]}
      />,
    );

    // The icon-only buttons carry an accessible name (the hand-rolled tables
    // this replaces did not).
    await user.click(screen.getByRole("button", { name: /edit/i }));
    await user.click(screen.getByRole("button", { name: /delete/i }));
    expect(onEdit).toHaveBeenCalledOnce();
    expect(onDelete).toHaveBeenCalledOnce();
  });
});
