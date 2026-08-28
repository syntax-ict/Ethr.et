import { describe, it, expect, vi } from "vitest";
import { render, screen, fireEvent } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import EmployeeImportPage from "@/app/(dashboard)/employees/import/page";

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), back: vi.fn() }),
}));

// The page is wrapped in <RoleGate minRole="hr_admin">; grant access directly.
vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({
    isAtLeast: () => true,
    hasRole: () => true,
    role: "hr_admin",
  }),
}));

const PREVIEW_URL = "*/api/v1/employees/import/preview";
const COMMIT_URL = "*/api/v1/employees/import/commit";

// One valid row (line 2) and one row that fails validation (line 3).
const PREVIEW = {
  headers: ["name", "email", "hire_date"],
  rows: [
    { name: "Abebe Kebede", email: "abebe@acme.et", hire_date: "2024-01-01" },
    { name: "", email: "not-an-email", hire_date: "" },
  ],
  errors: { 3: ["Name is required", "Email is invalid"] },
};

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

function uploadCsv(container: HTMLElement) {
  const input = container.querySelector("#csv-input") as HTMLInputElement;
  const file = new File(["name,email,hire_date\n"], "employees.csv", {
    type: "text/csv",
  });
  fireEvent.change(input, { target: { files: [file] } });
}

describe("Employee CSV import (S08)", () => {
  it("previews rows and flags validation errors", async () => {
    server.use(http.post(PREVIEW_URL, () => HttpResponse.json(PREVIEW)));

    const { container } = renderPage();
    expect(await screen.findByText("Upload CSV File")).toBeInTheDocument();

    uploadCsv(container);

    // Preview step: valid vs error counts are surfaced.
    expect(await screen.findByText("Total rows")).toBeInTheDocument();
    expect(screen.getByText("Valid rows")).toBeInTheDocument();
    expect(screen.getByText("Rows with errors")).toBeInTheDocument();

    // Green "Valid" badge for the good row, red error badge for the bad one.
    expect(screen.getByText("Valid")).toBeInTheDocument();
    expect(screen.getByText(/2\s+errors/i)).toBeInTheDocument();
  });

  it("commits the valid rows and shows the result", async () => {
    server.use(
      http.post(PREVIEW_URL, () => HttpResponse.json(PREVIEW)),
      http.post(COMMIT_URL, () =>
        HttpResponse.json({ created: 1, skipped: 1, errors: {} }),
      ),
    );

    const { container } = renderPage();
    await screen.findByText("Upload CSV File");
    uploadCsv(container);

    const importBtn = await screen.findByRole("button", {
      name: /Import 1 valid row/i,
    });
    fireEvent.click(importBtn);

    expect(await screen.findByText("Import Complete")).toBeInTheDocument();
  });
});
