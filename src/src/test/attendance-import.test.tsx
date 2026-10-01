import { describe, it, expect, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import AttendanceImportPage from "@/app/(dashboard)/attendance/import/page";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

/** Shaped like AttendanceImporter::preview(). */
const PREVIEW = {
  rows: [
    {
      employee_code: "EMP001",
      date: "2026-09-29",
      check_in_time: "08:30",
      check_out_time: "17:00",
      line: 2,
      errors: [],
      valid: true,
    },
  ],
  valid: 1,
  invalid: 0,
  errors: [],
};

function renderPage() {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={qc}>
      <AttendanceImportPage />
    </QueryClientProvider>,
  );
}

describe("<AttendanceImportPage>", () => {
  it("retries a failed commit under the same import key", async () => {
    // The commit is idempotent on import_key, but the page minted a fresh key
    // on every click — so retrying a commit whose response was lost imported
    // every row a second time instead of reporting them as skipped.
    const keys: string[] = [];
    server.use(
      http.post("*/api/v1/attendance/import/preview", () =>
        HttpResponse.json(PREVIEW),
      ),
      http.post("*/api/v1/attendance/import/commit", async ({ request }) => {
        const body = (await request.json()) as { import_key: string };
        keys.push(body.import_key);
        return keys.length === 1
          ? HttpResponse.json(
              { title: "Gateway Timeout", status: 504 },
              { status: 504 },
            )
          : HttpResponse.json(
              { created: 0, skipped: 1, errors: [] },
              { status: 201 },
            );
      }),
    );
    const user = userEvent.setup();
    const { container } = renderPage();

    const input = container.querySelector(
      'input[type="file"]',
    ) as HTMLInputElement;
    fireEvent.change(input, {
      target: {
        files: [
          new File(
            ["employee_code,date,check_in_time,check_out_time\n"],
            "punches.csv",
            { type: "text/csv" },
          ),
        ],
      },
    });

    const importButton = await screen.findByRole("button", {
      name: /Import 1 records/,
    });
    await user.click(importButton);
    await waitFor(() => expect(keys).toHaveLength(1));

    await user.click(
      await screen.findByRole("button", { name: /Import 1 records/ }),
    );
    await waitFor(() => expect(keys).toHaveLength(2));

    expect(keys[0]).toBeTruthy();
    expect(keys[1]).toBe(keys[0]);
  });
});
