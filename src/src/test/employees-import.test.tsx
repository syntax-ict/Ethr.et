import { describe, it, expect, vi } from "vitest";
import { render, screen, fireEvent } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import EmployeeImportPage from "@/app/(dashboard)/employees/import/page";

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), back: vi.fn() }),
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

const PREVIEW_URL = "*/api/v1/employees/import/preview";
const COMMIT_URL = "*/api/v1/employees/import/commit";
const BATCH = "01JBQ8Z6ZK3M9V2X7Y4W5T6R8S";

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

  it("sends only the rows the preview passed, so one bad row cannot fail the batch", async () => {
    // ImportCommitRequest validates every row it is sent (`rows.*.name`
    // required, `rows.*.email` an email). Sending the flagged row too turned
    // "rows with errors will be skipped" into a 422 for the whole file.
    let committed: { import_key: string; rows: unknown[] } | undefined;
    server.use(
      http.post(PREVIEW_URL, () => HttpResponse.json(PREVIEW)),
      http.post(COMMIT_URL, async ({ request }) => {
        committed = (await request.json()) as typeof committed;
        return HttpResponse.json(
          { created: 1, skipped: 0, matched: 0, users_created: 0, errors: [] },
          { status: 201 },
        );
      }),
    );

    const { container } = renderPage();
    await screen.findByText("Upload CSV File");
    uploadCsv(container);
    fireEvent.click(
      await screen.findByRole("button", { name: /Import 1 valid row/i }),
    );

    expect(await screen.findByText("Import Complete")).toBeInTheDocument();
    expect(committed?.rows).toEqual([PREVIEW.rows[0]]);
  });

  it("says why a file without its required columns cannot be imported", async () => {
    server.use(
      http.post(PREVIEW_URL, () =>
        HttpResponse.json({
          headers: ["email"],
          rows: [],
          errors: { 0: ["Missing required columns: name, hire_date"] },
        }),
      ),
    );

    const { container } = renderPage();
    await screen.findByText("Upload CSV File");
    uploadCsv(container);

    expect(
      await screen.findByText("Missing required columns: name, hire_date"),
    ).toBeInTheDocument();
    // It read "Import -1 valid rows", and was enabled.
    expect(
      screen.getByRole("button", { name: /Import 0 valid rows/i }),
    ).toBeDisabled();
  });

  it("gives a second file its own import key", async () => {
    // Rows without an employee_code are keyed by index under the import key,
    // so reusing the key skipped the second file's rows as already imported.
    const keys: string[] = [];
    server.use(
      http.post(PREVIEW_URL, () => HttpResponse.json(PREVIEW)),
      http.post(COMMIT_URL, async ({ request }) => {
        keys.push(
          ((await request.json()) as { import_key: string }).import_key,
        );
        return HttpResponse.json(
          { created: 1, skipped: 0, matched: 0, users_created: 0, errors: [] },
          { status: 201 },
        );
      }),
    );

    const { container } = renderPage();
    await screen.findByText("Upload CSV File");

    uploadCsv(container);
    fireEvent.click(
      await screen.findByRole("button", { name: /Import 1 valid row/i }),
    );
    fireEvent.click(
      await screen.findByRole("button", { name: "Import another file" }),
    );

    uploadCsv(container);
    fireEvent.click(
      await screen.findByRole("button", { name: /Import 1 valid row/i }),
    );
    await screen.findByText("Import Complete");

    expect(keys).toHaveLength(2);
    expect(keys[0]).not.toBe(keys[1]);
  });

  it("counts rows matched to an existing employee as not created", async () => {
    server.use(
      http.post(PREVIEW_URL, () => HttpResponse.json(PREVIEW)),
      http.post(COMMIT_URL, () =>
        HttpResponse.json(
          { created: 0, skipped: 0, matched: 1, users_created: 0, errors: [] },
          { status: 201 },
        ),
      ),
    );

    const { container } = renderPage();
    await screen.findByText("Upload CSV File");
    uploadCsv(container);
    fireEvent.click(
      await screen.findByRole("button", { name: /Import 1 valid row/i }),
    );

    const skipped = (await screen.findByText("Skipped")).parentElement!;
    expect(skipped).toHaveTextContent("1");
  });

  it("reaches the file picker from the keyboard", async () => {
    renderPage();
    expect(
      await screen.findByRole("button", { name: "Browse files" }),
    ).toBeInTheDocument();
  });
});

describe("Employee CSV import, 2026-10-15", () => {
  it("lists every problem by line, without the server's row prefix", async () => {
    server.use(
      http.post(PREVIEW_URL, () =>
        HttpResponse.json({
          ...PREVIEW,
          errors: {
            3: [
              "Row 3: The name field is required.",
              "Row 3: Email is invalid",
            ],
          },
        }),
      ),
    );

    const { container } = renderPage();
    await screen.findByText("Upload CSV File");
    uploadCsv(container);

    expect(await screen.findByText("What to fix")).toBeInTheDocument();
    expect(screen.getByText("The name field is required.")).toBeInTheDocument();
    expect(screen.getAllByText("Line 3")).toHaveLength(2);
  });

  it("says that a row with no code will be numbered", async () => {
    server.use(
      http.post(PREVIEW_URL, () => HttpResponse.json(PREVIEW)),
      http.get("*/api/v1/employees/next-code", () =>
        HttpResponse.json({ employee_code: "EMP-0007", format: "EMP-{SEQ:4}" }),
      ),
    );

    const { container } = renderPage();
    await screen.findByText("Upload CSV File");
    uploadCsv(container);

    expect(
      await screen.findByText(
        "1 row has no employee code. It gets the next number.",
      ),
    ).toBeInTheDocument();
  });

  it("sends login invitations only when the box is ticked", async () => {
    const bodies: Array<{ create_logins?: boolean }> = [];
    server.use(
      http.post(PREVIEW_URL, () => HttpResponse.json(PREVIEW)),
      http.post(COMMIT_URL, async ({ request }) => {
        bodies.push((await request.json()) as { create_logins?: boolean });
        return HttpResponse.json(
          {
            created: 1,
            skipped: 0,
            matched: 0,
            users_created: 1,
            invitations_batch: BATCH,
            invitations_queued: 1,
            in_person: 0,
            errors: [],
          },
          { status: 201 },
        );
      }),
      http.get(`*/api/v1/users/invitations/${BATCH}`, () =>
        HttpResponse.json({
          batch: BATCH,
          total: 1,
          queued: 1,
          sent: 0,
          failed: 0,
          skipped: 0,
          failures: [],
        }),
      ),
    );

    const { container } = renderPage();
    await screen.findByText("Upload CSV File");
    uploadCsv(container);
    fireEvent.click(await screen.findByLabelText(/Send a login invitation/));
    fireEvent.click(
      await screen.findByRole("button", { name: /Import 1 valid row/i }),
    );

    // Sent from the server's queue, a few a minute: the page says so rather
    // than claiming they have gone.
    expect(
      await screen.findByText("Invitations are being sent"),
    ).toBeInTheDocument();
    expect(screen.getByText("0 of 1 sent")).toBeInTheDocument();
    expect(bodies[0]?.create_logins).toBe(true);
  });

  it("lists the people with no email for an in-person activation", async () => {
    server.use(
      http.post(PREVIEW_URL, () => HttpResponse.json(PREVIEW)),
      http.post(COMMIT_URL, () =>
        HttpResponse.json(
          {
            created: 2,
            skipped: 0,
            matched: 0,
            users_created: 0,
            invitations_batch: null,
            invitations_queued: 0,
            in_person: 2,
            errors: [],
          },
          { status: 201 },
        ),
      ),
    );

    const { container } = renderPage();
    await screen.findByText("Upload CSV File");
    uploadCsv(container);
    fireEvent.click(await screen.findByLabelText(/Send a login invitation/));
    fireEvent.click(
      await screen.findByRole("button", { name: /Import 1 valid row/i }),
    );

    expect(
      await screen.findByText(
        "2 people have no email. Activate them in person from their employee pages.",
      ),
    ).toBeInTheDocument();
  });
});
