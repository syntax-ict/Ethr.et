import { describe, it, expect, vi } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { EmployeeNumberCard } from "@/features/employees/components/employee-number-card";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}));

function renderCard() {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={qc}>
      <EmployeeNumberCard
        employees={{
          number_format: "EMP-{SEQ:4}",
          next_code: "EMP-0012",
          invitation_reminders: true,
        }}
      />
    </QueryClientProvider>,
  );
}

describe("Employee number format card", () => {
  it("shows the next code for the saved format", () => {
    renderCard();

    expect(screen.getByText(/Next code: EMP-0012/)).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Save" })).toBeNull();
  });

  it("refuses a format with no single number token", () => {
    renderCard();

    fireEvent.change(screen.getByLabelText("Format"), {
      target: { value: "EMP-" },
    });

    expect(screen.getByText(/Use one \{SEQ:n\}/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Save" })).toBeDisabled();
  });

  it("saves a new format through PUT /settings", async () => {
    let body: { settings?: Record<string, unknown> } | undefined;
    server.use(
      http.put("*/api/v1/settings", async ({ request }) => {
        body = (await request.json()) as typeof body;
        return HttpResponse.json({ message: "ok" });
      }),
    );
    renderCard();

    fireEvent.change(screen.getByLabelText("Format"), {
      target: { value: "HC/{SEQ:3}" },
    });
    expect(screen.getByText(/Codes look like HC\/001/)).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Save" }));

    await waitFor(() =>
      expect(body?.settings).toEqual({ employee_number_format: "HC/{SEQ:3}" }),
    );
  });
});
