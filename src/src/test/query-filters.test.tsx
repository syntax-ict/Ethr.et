import { describe, it, expect, afterEach } from "vitest";
import { act, render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { useQueryFilters } from "@/lib/hooks/useQueryFilters";
import { FilterBar } from "@/components/shared/filter-bar";

/**
 * Filters lived in component state, so a reload lost them and a filtered view
 * could not be shared as a link (FRONTEND_REDESIGN_PLAN.md, FilterBar).
 */
function Harness() {
  const filters = useQueryFilters(
    { q: "", status: "all" },
    { status: ["all", "online", "offline"] },
  );
  return (
    <FilterBar activeCount={filters.activeCount} onClear={filters.clear}>
      <output aria-label="status">{filters.values.status}</output>
      <output aria-label="q">{filters.values.q}</output>
      <button onClick={() => filters.set({ status: "offline" })}>
        Offline
      </button>
      <input
        aria-label="Search"
        value={filters.values.q}
        onChange={(e) => filters.set({ q: e.target.value })}
      />
    </FilterBar>
  );
}

function at(url: string) {
  window.history.replaceState(null, "", url);
}

afterEach(() => at("/"));

describe("useQueryFilters with FilterBar", () => {
  it("opens with the filters a link carries", () => {
    at("/devices?status=offline&q=gate");
    render(<Harness />);
    expect(screen.getByLabelText("status")).toHaveTextContent("offline");
    expect(screen.getByLabelText("q")).toHaveTextContent("gate");
  });

  it("ignores a value the page does not allow, rather than send it to the API", () => {
    at("/devices?status=deleted");
    render(<Harness />);
    expect(screen.getByLabelText("status")).toHaveTextContent("all");
  });

  it("writes a change to the address, without a navigation", async () => {
    at("/devices");
    const before = window.history.length;
    render(<Harness />);
    await userEvent
      .setup()
      .click(screen.getByRole("button", { name: "Offline" }));

    expect(window.location.search).toBe("?status=offline");
    expect(window.history.length).toBe(before);
    expect(screen.getByLabelText("status")).toHaveTextContent("offline");
  });

  it("keeps an unfiltered page's address clean", async () => {
    at("/devices?status=offline");
    render(<Harness />);
    await userEvent
      .setup()
      .click(screen.getByRole("button", { name: /clear filters/i }));

    expect(window.location.search).toBe("");
    expect(screen.getByLabelText("status")).toHaveTextContent("all");
  });

  it("offers Clear filters only while a filter is set", async () => {
    at("/devices");
    render(<Harness />);
    expect(
      screen.queryByRole("button", { name: /clear filters/i }),
    ).not.toBeInTheDocument();

    await userEvent.setup().type(screen.getByLabelText("Search"), "gate");
    expect(window.location.search).toBe("?q=gate");
    expect(
      screen.getByRole("button", { name: /clear filters/i }),
    ).toBeInTheDocument();
  });

  it("follows the browser's back and forward buttons", () => {
    at("/devices");
    render(<Harness />);
    act(() => {
      at("/devices?status=online");
      window.dispatchEvent(new PopStateEvent("popstate"));
    });
    expect(screen.getByLabelText("status")).toHaveTextContent("online");
  });

  it("names the group for a screen reader", () => {
    render(<Harness />);
    expect(screen.getByRole("group", { name: "Filters" })).toBeInTheDocument();
  });
});
