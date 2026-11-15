import { beforeEach, describe, it, expect, vi } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { toast } from "sonner";
import { server } from "./msw/server";
import { buildEmployee } from "./msw/handlers";
import { employeeTemplateText } from "@/features/employees/components/import-column-guide";
import EmployeesPage from "@/app/(dashboard)/employees/page";
import {
  buildCompleteWorkbook,
  TooManyToComplete,
} from "@/features/employees/complete-workbook";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const saved = vi.hoisted(() => ({ files: [] as string[] }));
vi.mock("@/lib/utils/csv-export", async (original) => ({
  ...(await original<typeof import("@/lib/utils/csv-export")>()),
  saveBlob: (name: string) => saved.files.push(name),
}));

const built = vi.hoisted(() => ({
  calls: [] as { calendar: unknown; rows: Record<string, string>[] }[],
}));
vi.mock("@/features/onboarding/v2/xlsx-grid", async (original) => ({
  ...(await original<typeof import("@/features/onboarding/v2/xlsx-grid")>()),
  buildTemplate: async (
    _spec: unknown,
    calendar: unknown,
    _text: unknown,
    rows: Record<string, string>[],
  ) => {
    built.calls.push({ calendar, rows });
    return new ArrayBuffer(8);
  },
}));

const SPEC = {
  headers: ["name", "phone", "hire_date", "status"],
  required: ["name"],
  text_columns: ["phone"],
  date_columns: ["hire_date"],
  lists: {},
};

const TEXT = employeeTemplateText((key, fallback) => fallback ?? key);

/**
 * What HR downloads to fill in: the people the list shows, prefilled, with
 * every blank left for them. It is the export, in the template's columns.
 */
function renderPage(total: number) {
  server.use(
    http.get("*/api/v1/auth/me", () =>
      HttpResponse.json({
        user: { public_id: "U1", name: "HR", role: "hr_admin" },
        tenant: {
          public_id: "T1",
          name: "Demo",
          timezone: "Africa/Addis_Ababa",
        },
        permissions: ["employee.viewAny", "employee.create"],
      }),
    ),
    http.get("*/api/v1/employees", () =>
      HttpResponse.json({
        data: [buildEmployee()],
        meta: {
          current_page: 1,
          last_page: 1,
          per_page: 25,
          total,
          from: 1,
          to: 1,
        },
        links: { first: "", last: "", prev: null, next: null },
      }),
    ),
    http.post("*/api/v1/employees/import/template", () =>
      HttpResponse.json(SPEC),
    ),
  );

  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={qc}>
      <EmployeesPage />
    </QueryClientProvider>,
  );
}

describe("Download to complete", () => {
  beforeEach(() => {
    built.calls = [];
    saved.files = [];
    vi.mocked(toast.success).mockClear();
    vi.mocked(toast.error).mockClear();
  });

  it("saves a workbook of the people the list shows, in the Gregorian calendar, and says where to bring it back", async () => {
    let asked: URLSearchParams | undefined;
    server.use(
      http.get("*/api/v1/employees/export", ({ request }) => {
        asked = new URL(request.url).searchParams;
        return HttpResponse.json({
          csv: [
            "name,phone,hire_date,status",
            "Abebe Kebede,'+251911223344,2020-01-01,hired",
            "Sara Tesfaye,,2021-03-04,resigned",
          ].join("\n"),
          count: 2,
        });
      }),
    );

    renderPage(2);
    await screen.findByText("Abebe Kebede");
    fireEvent.click(
      screen.getByRole("button", { name: "Download to complete" }),
    );

    await waitFor(() =>
      expect(saved.files).toEqual(["ethr-employees-to-complete.xlsx"]),
    );
    expect(built.calls).toHaveLength(1);
    expect(built.calls[0].calendar).toBe("gregorian");
    // The terminal's quote is taken off a phone; a status the import would
    // refuse (someone who has left) is left blank.
    expect(built.calls[0].rows).toEqual([
      {
        name: "Abebe Kebede",
        phone: "+251911223344",
        hire_date: "2020-01-01",
        status: "hired",
      },
      {
        name: "Sara Tesfaye",
        phone: "",
        hire_date: "2021-03-04",
        status: "",
      },
    ]);
    expect(asked).toBeDefined();
    expect(toast.success).toHaveBeenCalledWith(
      expect.stringContaining("bring the file back under Import"),
    );
  });

  it("honours the filters the list has, as the export does", async () => {
    let asked: URLSearchParams | undefined;
    server.use(
      http.get("*/api/v1/employees/export", ({ request }) => {
        asked = new URL(request.url).searchParams;
        return HttpResponse.json({ csv: "name\nAbebe Kebede\n", count: 1 });
      }),
    );
    window.history.replaceState(null, "", "?missing=position");

    renderPage(1);
    await screen.findByText("Abebe Kebede");
    fireEvent.click(
      screen.getByRole("button", { name: "Download to complete" }),
    );

    await waitFor(() => expect(asked).toBeDefined());
    expect(asked!.get("filter[missing]")).toBe("position");
    window.history.replaceState(null, "", "/");
  });

  it("builds nothing, and says to narrow the list, when one file cannot hold everyone", async () => {
    let exported = 0;
    server.use(
      http.get("*/api/v1/employees/export", () => {
        exported++;
        return HttpResponse.json({ csv: "name\n", count: 2500 });
      }),
    );

    renderPage(2500);
    await screen.findByText("Abebe Kebede");
    fireEvent.click(
      screen.getByRole("button", { name: "Download to complete" }),
    );

    await waitFor(() => expect(toast.error).toHaveBeenCalled());
    expect(toast.error).toHaveBeenCalledWith(
      expect.stringContaining("2500 people and one file takes at most 2000"),
    );
    expect(exported).toBe(0);
    expect(built.calls).toHaveLength(0);
    expect(saved.files).toHaveLength(0);
  });
});

describe("buildCompleteWorkbook", () => {
  beforeEach(() => {
    built.calls = [];
  });

  it("refuses an export longer than one file takes, and builds nothing", async () => {
    await expect(
      buildCompleteWorkbook({ csv: "name\nA\n", count: 2001 }, SPEC, TEXT),
    ).rejects.toBeInstanceOf(TooManyToComplete);
    expect(built.calls).toHaveLength(0);
  });

  it("takes an export of exactly the most one file takes", async () => {
    await buildCompleteWorkbook({ csv: "name\nA\n", count: 2000 }, SPEC, TEXT);
    expect(built.calls).toHaveLength(1);
    expect(built.calls[0].rows).toEqual([{ name: "A" }]);
  });
});
