import { describe, it, expect, vi } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { BulkActionsBar } from "@/features/employees/components/bulk-actions-bar";

function paginated<T>(rows: T[]) {
  return {
    data: rows,
    meta: {
      current_page: 1,
      last_page: 1,
      per_page: 25,
      total: rows.length,
      from: rows.length ? 1 : 0,
      to: rows.length,
    },
    links: { first: "", last: "", prev: null, next: null },
  };
}

function renderBar(onComplete = vi.fn()) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  const Wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  );

  server.use(
    http.get("*/api/v1/organization/departments", () =>
      HttpResponse.json(
        paginated([{ public_id: "dept-eng", name: "Engineering" }]),
      ),
    ),
    http.get("*/api/v1/organization/branches", () =>
      HttpResponse.json(
        paginated([{ public_id: "branch-hq", name: "Headquarters" }]),
      ),
    ),
  );

  render(
    <BulkActionsBar selectedIds={["emp-1", "emp-2"]} onComplete={onComplete} />,
    { wrapper: Wrapper },
  );

  return { onComplete };
}

describe("<BulkActionsBar>", () => {
  it("shows the selection count and the three bulk actions", () => {
    renderBar();

    expect(screen.getByText("2 selected")).toBeInTheDocument();
    expect(
      screen.getByRole("button", { name: /change department/i }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("button", { name: /change branch/i }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("button", { name: /change status/i }),
    ).toBeInTheDocument();
  });

  it("opens a dialog naming the action and the affected count", () => {
    renderBar();

    fireEvent.click(screen.getByRole("button", { name: /change branch/i }));

    expect(screen.getByRole("dialog")).toBeInTheDocument();
    expect(
      screen.getByRole("heading", { name: "Change branch" }),
    ).toBeInTheDocument();
    expect(
      screen.getByText("This will update 2 selected employees."),
    ).toBeInTheDocument();
  });

  it("disables Confirm until a value is chosen", () => {
    renderBar();

    fireEvent.click(screen.getByRole("button", { name: /change department/i }));

    expect(screen.getByRole("button", { name: /confirm/i })).toBeDisabled();
  });

  it("posts the selected status to bulk-update and clears via onComplete", async () => {
    let requestBody: unknown;
    server.use(
      http.post("*/api/v1/employees/bulk-update", async ({ request }) => {
        requestBody = await request.json();
        return HttpResponse.json({ updated: 2 });
      }),
    );
    const { onComplete } = renderBar();

    fireEvent.click(screen.getByRole("button", { name: /change status/i }));
    fireEvent.click(screen.getByRole("combobox"));
    fireEvent.click(await screen.findByRole("option", { name: "Retired" }));
    fireEvent.click(screen.getByRole("button", { name: /confirm/i }));

    await waitFor(() => expect(onComplete).toHaveBeenCalledTimes(1));
    expect(requestBody).toEqual({
      employee_ids: ["emp-1", "emp-2"],
      status: "retired",
    });
  });
});
