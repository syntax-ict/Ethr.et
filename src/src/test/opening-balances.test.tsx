import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import OpeningBalancesPage from "@/app/(dashboard)/settings/opening-balances/page";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const granted = new Set(["leave.adjustBalance"]);
vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({
    hasPermission: (p: string) => granted.has(p),
    can: {},
    role: "hr_admin",
  }),
}));

/**
 * Opening leave and loan balances from a previous system (2026-10-20,
 * blueprint M4, M5): preview every row with its problems, and import only
 * when all of them are valid.
 */
const ROW_OK = {
  employee_code: "E-001",
  employee_name: "Abebe Kebede",
  leave_type_code: "annual",
  year: "2026",
  entitled_days: "18",
  used_days: "4.5",
  carried_days: "2",
  line: 2,
  errors: [],
  valid: true,
};
const ROW_BAD = {
  ...ROW_OK,
  employee_code: "E-404",
  employee_name: null,
  line: 3,
  errors: ["No employee has the code E-404."],
  valid: false,
};

function renderPage(
  preview: Record<string, unknown>,
  onCommit: (body: unknown) => void = () => {},
) {
  server.use(
    http.post("*/api/v1/imports/balances/leave/preview", () =>
      HttpResponse.json(preview),
    ),
    http.post("*/api/v1/imports/balances/leave/commit", async ({ request }) => {
      onCommit(await request.json());
      return HttpResponse.json({
        committed: true,
        created: 1,
        updated: 0,
        skipped: 0,
        rows: [],
      });
    }),
  );
  const queryClient = new QueryClient({
    defaultOptions: { mutations: { retry: false } },
  });
  const Wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  );
  return render(<OpeningBalancesPage />, { wrapper: Wrapper });
}

const FILE = new File(["employee_code\n"], "leave.csv", { type: "text/csv" });

describe("<OpeningBalancesPage>", () => {
  it("shows only the sections the caller may import", () => {
    renderPage({ rows: [], valid: 0, invalid: 0, errors: [] });

    expect(screen.getByText("Leave balances")).toBeInTheDocument();
    expect(screen.queryByText("Loans")).not.toBeInTheDocument();
  });

  it("lists each row's problems and will not import while any remain", async () => {
    const user = userEvent.setup();
    renderPage({ rows: [ROW_OK, ROW_BAD], valid: 1, invalid: 1, errors: [] });

    await user.upload(screen.getByLabelText("Leave balances CSV file"), FILE);

    expect(
      await screen.findByText("No employee has the code E-404."),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("button", { name: "Import 1 rows" }),
    ).toBeDisabled();
  });

  it("imports the previewed rows when all are valid", async () => {
    const commits: unknown[] = [];
    const user = userEvent.setup();
    renderPage({ rows: [ROW_OK], valid: 1, invalid: 0, errors: [] }, (b) =>
      commits.push(b),
    );

    await user.upload(screen.getByLabelText("Leave balances CSV file"), FILE);
    await user.click(
      await screen.findByRole("button", { name: "Import 1 rows" }),
    );

    await vi.waitFor(() => expect(commits).toEqual([{ rows: [ROW_OK] }]));
  });
});
