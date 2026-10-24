import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { AbsencesBanner } from "@/features/payroll/components/absences-banner";

function wrapper({ children }: { children: ReactNode }) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return <QueryClientProvider client={client}>{children}</QueryClientProvider>;
}

describe("<AbsencesBanner>", () => {
  it("names who missed which days and says pay was not reduced", () => {
    localStorage.setItem("ethr.calendar", "gregorian");
    render(
      <AbsencesBanner
        absences={{
          count: 2,
          days: 3,
          employees: [
            {
              employee_public_id: "01HZEMP0000000000000000001",
              employee_name: "Abebe Kebede",
              days: 2,
              dates: ["2026-06-10", "2026-06-11"],
            },
            {
              employee_public_id: null,
              employee_name: "Sara Tesfaye",
              days: 1,
              dates: ["2026-06-22"],
            },
          ],
        }}
      />,
      { wrapper },
    );
    localStorage.removeItem("ethr.calendar");

    expect(
      screen.getByText(
        "2 employee(s) missed 3 workday(s) with no attendance, leave or holiday on record.",
      ),
    ).toBeInTheDocument();
    expect(
      screen.getByText(/Pay was not reduced for these days/),
    ).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Abebe Kebede" })).toHaveAttribute(
      "href",
      "/employees/01HZEMP0000000000000000001",
    );
    expect(screen.getByText("Sara Tesfaye")).toBeInTheDocument();
    const items = screen.getAllByRole("listitem");
    expect(items[0]).toHaveTextContent(/^Abebe Kebede: 2 day\(s\): .+, .+$/);
    expect(items[1]).toHaveTextContent(/^Sara Tesfaye: 1 day\(s\): [^,]+$/);
  });

  it("shows nothing when nobody missed a workday", () => {
    const { container } = render(
      <AbsencesBanner absences={{ count: 0, days: 0, employees: [] }} />,
      { wrapper },
    );
    expect(container).toBeEmptyDOMElement();
  });
});
