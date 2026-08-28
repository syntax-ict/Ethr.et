import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";

import { OverviewTab } from "@/app/(dashboard)/analytics/overview-tab";
import { AttendanceTab } from "@/app/(dashboard)/analytics/attendance-tab";
import { PayrollTab } from "@/app/(dashboard)/analytics/payroll-tab";
import { WorkforceTab } from "@/app/(dashboard)/analytics/workforce-tab";

/**
 * These views used to guard with `if (isLoading || !data) return <Skeleton />`.
 * On a failed fetch `isLoading` is false and `data` is undefined, so they rendered
 * a skeleton *forever* — no message, no retry, indistinguishable from a slow
 * network. They now route through QueryBoundary, which has an error state.
 *
 * The assertion is deliberately "an error is shown AND no skeleton remains":
 * asserting only the former would still pass if the skeleton never went away.
 */
function renderWithClient(ui: React.ReactElement) {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(<QueryClientProvider client={qc}>{ui}</QueryClientProvider>);
}

const CASES = [
  {
    name: "OverviewTab",
    url: "*/api/v1/dashboard/executive",
    element: <OverviewTab />,
  },
  {
    name: "AttendanceTab",
    url: "*/api/v1/dashboard/executive/attendance",
    element: <AttendanceTab />,
  },
  {
    name: "PayrollTab",
    url: "*/api/v1/dashboard/executive/payroll",
    element: <PayrollTab />,
  },
  {
    name: "WorkforceTab",
    url: "*/api/v1/dashboard/executive/workforce",
    element: <WorkforceTab />,
  },
] as const;

describe("dashboard analytics error states", () => {
  for (const { name, url, element } of CASES) {
    it(`${name} shows an error with a retry instead of a stuck skeleton`, async () => {
      server.use(http.get(url, () => new HttpResponse(null, { status: 500 })));

      const { container } = renderWithClient(element);

      expect(await screen.findByText("Couldn't load this")).toBeInTheDocument();
      expect(
        screen.getByRole("button", { name: /try again/i }),
      ).toBeInTheDocument();
      expect(container.querySelectorAll(".animate-pulse")).toHaveLength(0);
    });
  }
});
