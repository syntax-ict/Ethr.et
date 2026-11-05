import { describe, it, expect } from "vitest";
import { render, screen, within } from "@testing-library/react";
import {
  Heatmap,
  HeatmapTable,
  heatmapColor,
  heatmapLevel,
} from "@/components/dashboard/heatmap";

/**
 * The heatmap primitive (charts upgrade, 2026-11-04): a CSS grid of cells
 * shaded from the muted surface to a theme colour, a legend, and the same
 * figures as a table for `<ChartCard>`'s table view.
 */
const ROWS = [
  { key: "mon", label: "ሰኞ", name: "Monday" },
  { key: "tue", label: "ማክሰኞ", name: "Tuesday" },
];
const COLUMNS = [
  { key: "08", label: "08" },
  { key: "09", label: "09" },
  { key: "10", label: "10" },
];
const VALUES: Record<string, Record<string, number>> = {
  mon: { "08": 10, "09": 2 },
  tue: { "09": 5, "10": 1 },
};
const value = (row: string, column: string) => VALUES[row]?.[column];

describe("heatmapLevel", () => {
  it("maps nothing to 0 and the top value to the darkest shade", () => {
    expect(heatmapLevel(undefined, 10)).toBe(0);
    expect(heatmapLevel(0, 10)).toBe(0);
    expect(heatmapLevel(1, 10)).toBe(1);
    expect(heatmapLevel(5, 10)).toBe(3);
    expect(heatmapLevel(10, 10)).toBe(5);
    expect(heatmapLevel(3, 0)).toBe(0);
  });

  it("colours through the theme, never a literal", () => {
    expect(heatmapColor(0, "var(--chart-1)")).toBe("var(--muted)");
    expect(heatmapColor(5, "var(--chart-1)")).toContain("var(--chart-1) 100%");
    expect(heatmapColor(1, "var(--chart-1)")).toContain("var(--card)");
  });
});

describe("<Heatmap>", () => {
  it("draws one cell per row and column, shaded by value, with a legend", () => {
    render(
      <Heatmap
        rows={ROWS}
        columns={COLUMNS}
        value={value}
        format={(v) => `${v} punches`}
      />,
    );

    const cells = screen.getAllByRole("img");
    expect(cells).toHaveLength(6);

    const busiest = screen.getByRole("img", { name: "Monday, 08: 10 punches" });
    expect(busiest).toHaveAttribute("data-level", "5");
    const quiet = screen.getByRole("img", { name: "Tuesday, 10: 1 punches" });
    expect(quiet).toHaveAttribute("data-level", "1");
    const empty = screen.getByRole("img", { name: "Monday, 10: 0 punches" });
    expect(empty).toHaveAttribute("data-level", "0");
    expect(empty).toHaveStyle({ background: "var(--muted)" });

    // Row labels in Amharic are rendered whole, not clipped.
    expect(screen.getByText("ሰኞ")).toBeInTheDocument();
    expect(screen.getByText("ማክሰኞ")).toBeInTheDocument();
    expect(screen.getByText("None")).toBeInTheDocument();
    expect(screen.getByText("Fewer")).toBeInTheDocument();
    expect(screen.getByText("More")).toBeInTheDocument();
  });

  it("labels every nth column where asked, keeping every column", () => {
    render(
      <Heatmap
        rows={ROWS}
        columns={COLUMNS}
        value={value}
        columnLabelEvery={2}
      />,
    );
    expect(screen.getByText("08")).toBeInTheDocument();
    expect(screen.queryByText("09")).toBeNull();
    expect(screen.getByText("10")).toBeInTheDocument();
    expect(screen.getAllByRole("img")).toHaveLength(6);
  });
});

describe("<HeatmapTable>", () => {
  it("lists the same figures as rows with a header per column", () => {
    render(
      <HeatmapTable
        caption="Punches by hour"
        rowHeading="Day"
        rows={ROWS}
        columns={COLUMNS}
        value={value}
      />,
    );

    const table = screen.getByRole("table", { name: "Punches by hour" });
    expect(
      within(table)
        .getAllByRole("columnheader")
        .map((th) => th.textContent),
    ).toEqual(["Day", "08", "09", "10"]);
    expect(
      within(table).getByRole("row", { name: /ሰኞ 10 2 0/ }),
    ).toBeInTheDocument();
    expect(
      within(table).getByRole("row", { name: /ማክሰኞ 0 5 1/ }),
    ).toBeInTheDocument();
  });
});
