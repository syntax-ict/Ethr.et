import { describe, it, expect } from "vitest";
import { render } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { renderHook } from "@testing-library/react";
import type { ReactNode } from "react";
import { CalendarProvider } from "@/lib/calendar/calendar-context";
import { PrintScope, usePrintDateRange } from "@/components/shared/print-scope";

function wrapper({ children }: { children: ReactNode }) {
  const client = new QueryClient();
  return (
    <QueryClientProvider client={client}>
      <CalendarProvider>{children}</CalendarProvider>
    </QueryClientProvider>
  );
}

describe("<PrintScope>", () => {
  it("prints each filter as label and value, hidden on screen", () => {
    const { container } = render(
      <PrintScope
        items={[
          { label: "Status", value: "Late" },
          { label: "Department", value: "Finance" },
        ]}
      />,
      { wrapper },
    );

    const line = container.querySelector(".print-scope");
    expect(line).toHaveClass("hidden");
    expect(line?.textContent).toBe("Status: Late · Department: Finance");
  });

  it("says All for a filter that is not set", () => {
    const { container } = render(
      <PrintScope
        items={[
          { label: "Status", value: "" },
          { label: "Branch", value: null },
        ]}
      />,
      { wrapper },
    );

    expect(container.querySelector(".print-scope")?.textContent).toBe(
      "Status: All · Branch: All",
    );
  });

  it("renders nothing without items", () => {
    const { container } = render(<PrintScope items={[]} />, { wrapper });
    expect(container.querySelector(".print-scope")).toBeNull();
  });
});

describe("usePrintDateRange", () => {
  it("reads a range, an open end and no range", () => {
    const { result } = renderHook(() => usePrintDateRange(), { wrapper });
    const range = result.current;

    expect(range("2026-11-01", "2026-11-09")).toMatch(/ to /);
    expect(range("2026-11-01", "")).toMatch(/^from /);
    expect(range("", "2026-11-09")).toMatch(/^until /);
    expect(range("", "")).toBe("");
  });
});
