import { describe, it, expect, vi } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import EmployeeImportPage from "@/app/(dashboard)/employees/import/page";
import type { MigrationBatch } from "@/features/onboarding/v2/types";
import { readWorkbook } from "@/features/onboarding/v2/xlsx-grid";

const push = vi.fn();
vi.mock("next/navigation", () => ({
  useRouter: () => ({ push, back: vi.fn() }),
}));

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

// The page is wrapped in <RoleGate anyPermission={["manageEmployees"]}>;
// grant access directly.
vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({
    isAtLeast: () => true,
    hasRole: () => true,
    role: "hr_admin",
    can: { manageEmployees: true },
  }),
}));

const saved: { name: string; blob: Blob }[] = [];
const savedCsv: string[] = [];
vi.mock("@/lib/utils/csv-export", () => ({
  saveBlob: (name: string, blob: Blob) => saved.push({ name, blob }),
  saveCsv: (name: string) => savedCsv.push(name),
}));

/*
 * The employee import is the staged review every list goes through (One
 * employee identity, PR 6). It had a pipeline of its own: upload, preview,
 * commit, no review, and a person already in ETHR was counted and skipped.
 */

const BATCH: MigrationBatch = {
  public_id: "01HZBATCH0000000000000009",
  source_type: "csv",
  source_ref: null,
  status: "reviewing",
  totals: null,
  summary: { new: 1, matched: 1 },
  rows: [
    {
      public_id: "01HZROW00000000000000091",
      display_name: "Abebe Kebede",
      external_identifier: "EMP-1",
      match_outcome: "matched",
      match_confidence: 1,
      candidates: [],
      resolved_employee_public_id: "01HZEMP0000000000000001",
      action: "merge",
      changes: [
        {
          field: "phone",
          kind: "fill",
          current: null,
          incoming: "+251911223344",
          masked: false,
          accepted: false,
          taken: false,
        },
      ],
      can_apply_changes: true,
      scheduled_action: false,
      processed_at: null,
    },
    {
      public_id: "01HZROW00000000000000092",
      display_name: "Hanna Girma",
      external_identifier: null,
      match_outcome: "new",
      match_confidence: null,
      candidates: [],
      resolved_employee_public_id: null,
      action: "create",
      changes: [],
      can_apply_changes: false,
      scheduled_action: false,
      processed_at: null,
    },
  ],
};

function renderPage() {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  server.use(
    http.get("*/devices", () =>
      HttpResponse.json({ data: [], meta: {}, links: {} }),
    ),
  );
  return render(
    <QueryClientProvider client={qc}>
      <EmployeeImportPage />
    </QueryClientProvider>,
  );
}

function chooseFile(text: string, name = "employees.csv") {
  fireEvent.change(screen.getByTestId("people-file"), {
    target: { files: [new File([text], name, { type: "text/csv" })] },
  });
}

describe("Employee import", () => {
  it("is about a file: no attendance device, and every column explained", () => {
    renderPage();

    expect(
      screen.getByRole("heading", { name: "Import employees from a file" }),
    ).toBeInTheDocument();
    expect(screen.queryByText("Discover enrolled users")).toBeNull();
    expect(screen.queryByText("Connect a device")).toBeNull();
    // The two columns a list can now bring, beside the ones it always could.
    expect(screen.getByText("external_id")).toBeInTheDocument();
    expect(screen.getByText("status")).toBeInTheDocument();
    expect(screen.getByText("salary")).toBeInTheDocument();
  });

  it("reaches the file picker from the keyboard", () => {
    renderPage();

    expect(
      screen.getByRole("button", { name: "Choose an Excel or CSV file" }),
    ).toBeEnabled();
  });

  it("reads the file on the server, then shows each person matched before anyone is added", async () => {
    let read: { grid: string[][] } | null = null;
    let staged: { grid: string[][]; calendar: string } | null = null;
    server.use(
      http.post("*/onboarding/migration/read", async ({ request }) => {
        read = (await request.json()) as typeof read;
        return HttpResponse.json({
          people: 2,
          header: true,
          columns: ["name", "employee_code", "phone", "salary"],
          ignored: [],
          calendar: "gregorian",
          calendar_from_header: false,
          without_hire_date: 2,
          problems: [],
        });
      }),
      http.post("*/onboarding/migration/rows", async ({ request }) => {
        staged = (await request.json()) as typeof staged;
        return HttpResponse.json(BATCH, { status: 201 });
      }),
      http.get("*/onboarding/migration/batches/:batchId", () =>
        HttpResponse.json(BATCH),
      ),
    );

    renderPage();
    chooseFile(
      "Name,Staff ID,Mobile,Salary\nAbebe Kebede,EMP-1,0911223344,12500\nHanna Girma,,,",
    );

    expect(await screen.findByText(/2 people ready/)).toBeInTheDocument();
    expect(
      screen.getByText(/Columns: Name, Employee number, Phone, Salary/),
    ).toBeInTheDocument();
    // The file's own words went to the server; it said what they mean.
    expect(read!.grid[0]).toEqual(["Name", "Staff ID", "Mobile", "Salary"]);

    fireEvent.click(screen.getByText("Stage for review"));

    // Nobody has been added: this is the review.
    expect(await screen.findByText("Abebe Kebede")).toBeInTheDocument();
    expect(screen.getByText("Hanna Girma")).toBeInTheDocument();
    expect(screen.getByText(/Fills in Phone/)).toBeInTheDocument();
    expect(staged!.grid).toHaveLength(3);
    expect(staged!.calendar).toBe("gregorian");
  });

  it("downloads the one employee template as a workbook, its date columns naming the calendar", async () => {
    saved.length = 0;
    savedCsv.length = 0;
    server.use(
      http.post("*/employees/import/template", () =>
        HttpResponse.json({
          template: "name,hire_date,date_of_birth,gender,department\n",
          headers: [
            "name",
            "hire_date",
            "date_of_birth",
            "gender",
            "department",
          ],
          required: ["name"],
          text_columns: [],
          date_columns: ["hire_date", "date_of_birth"],
          lists: { gender: ["male", "female"], department: ["Finance"] },
        }),
      ),
    );

    renderPage();
    fireEvent.click(screen.getByText("Ethiopian (E.C.)"));
    fireEvent.click(screen.getByText("Download template"));

    await waitFor(() => expect(saved).toHaveLength(1), { timeout: 10_000 });
    expect(saved[0].name).toBe("ethr-employee-template.xlsx");
    expect(savedCsv).toEqual([]);

    // It reads back as its own header row, in the calendar chosen.
    const bytes = await saved[0].blob.arrayBuffer();
    expect(await readWorkbook(bytes)).toEqual([
      [
        "name",
        "hire_date (E.C.)",
        "date_of_birth (E.C.)",
        "gender",
        "department",
      ],
    ]);
  });

  it("refuses a file over 5 MB without reading it into the page", async () => {
    const { toast } = await import("sonner");
    const { container } = renderPage();
    const big = new File(["x"], "huge.csv", { type: "text/csv" });
    Object.defineProperty(big, "size", { value: 5 * 1024 * 1024 + 1 });

    fireEvent.change(screen.getByTestId("people-file"), {
      target: { files: [big] },
    });

    await waitFor(() => expect(toast.error).toHaveBeenCalled());
    expect(
      (container.querySelector("textarea") as HTMLTextAreaElement).value,
    ).toBe("");
  });
});
