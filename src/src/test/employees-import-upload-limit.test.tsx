import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { toast } from "sonner";
import { server } from "./msw/server";
import EmployeeImportPage from "@/app/(dashboard)/employees/import/page";

/**
 * The employee import against the server's real upload limit. Production runs
 * PHP's stock 2 MB (measured 2026-10-17) under the form's 5 MB, and the page
 * checked neither: a 3 MB file failed on the server and was reported as a file
 * that "could not be read".
 */
vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), back: vi.fn() }),
}));

vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({
    isAtLeast: () => true,
    hasRole: () => true,
    role: "hr_admin",
    can: { manageEmployees: true },
  }),
}));

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const PREVIEW_URL = "*/api/v1/employees/import/preview";

function renderPage() {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={qc}>
      <EmployeeImportPage />
    </QueryClientProvider>,
  );
}

function csvOf(bytes: number): File {
  const file = new File(["name,email,hire_date\n"], "employees.csv", {
    type: "text/csv",
  });
  Object.defineProperty(file, "size", { value: bytes });
  return file;
}

function choose(container: HTMLElement, file: File) {
  const input = container.querySelector("#csv-input") as HTMLInputElement;
  fireEvent.change(input, { target: { files: [file] } });
}

let previewed = false;

beforeEach(() => {
  vi.mocked(toast.error).mockClear();
  previewed = false;
  server.use(
    http.get("*/api/v1/auth/me", () =>
      HttpResponse.json({
        user: { public_id: "U1", role: "hr_admin" },
        tenant: null,
        permissions: ["employee.create"],
        upload_limit_bytes: 2 * 1024 * 1024,
      }),
    ),
    http.post(PREVIEW_URL, () => {
      previewed = true;
      return HttpResponse.json({ headers: [], rows: [], errors: {} });
    }),
  );
});

describe("Employee import, against the upload limits", () => {
  it("refuses a file over the server's limit, naming both sizes, and sends nothing", async () => {
    const { container } = renderPage();
    await screen.findByText("Upload CSV File");
    // Let /auth/me answer, so the server's limit is known.
    await new Promise((r) => setTimeout(r, 50));

    choose(container, csvOf(3 * 1024 * 1024));

    await waitFor(() =>
      expect(toast.error).toHaveBeenCalledWith(
        "This file is 3 MB. The largest file you can upload here is 2 MB.",
      ),
    );
    expect(previewed).toBe(false);
  });

  it("refuses a file over the import's own 5 MB limit", async () => {
    const { container } = renderPage();
    await screen.findByText("Upload CSV File");

    choose(container, csvOf(6 * 1024 * 1024));

    await waitFor(() =>
      expect(toast.error).toHaveBeenCalledWith(
        "The file must be 5 MB or smaller.",
      ),
    );
    expect(previewed).toBe(false);
  });

  it("shows the server's reason when the upload is refused", async () => {
    server.use(
      http.post(PREVIEW_URL, () =>
        HttpResponse.json(
          {
            type: "validation_error",
            title: "Validation Failed",
            status: 422,
            detail:
              "The file could not be uploaded. It may be larger than the server accepts.",
          },
          { status: 422 },
        ),
      ),
    );
    const { container } = renderPage();
    await screen.findByText("Upload CSV File");

    choose(container, csvOf(1024));

    await waitFor(() =>
      expect(toast.error).toHaveBeenCalledWith(
        "The file could not be uploaded. It may be larger than the server accepts.",
      ),
    );
  });
});
