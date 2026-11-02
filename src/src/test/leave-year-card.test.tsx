import { describe, it, expect, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { LeaveYearCard } from "@/features/leave/components/leave-year-card";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

function serve(permissions: string[], leave: Record<string, unknown>) {
  server.use(
    http.get("*/api/v1/auth/me", () =>
      HttpResponse.json({
        user: { public_id: "U1", name: "Admin", role: "tenant_admin" },
        tenant: {
          public_id: "T1",
          name: "Demo",
          timezone: "Africa/Addis_Ababa",
        },
        permissions,
      }),
    ),
    http.get("*/api/v1/settings", () =>
      HttpResponse.json({ leave: { working_days: [1, 2, 3, 4, 5], ...leave } }),
    ),
  );
}

function renderCard() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const Wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  );
  return render(<LeaveYearCard />, { wrapper: Wrapper });
}

describe("LeaveYearCard", () => {
  it("shows the organisation's leave year and lets an admin change it", async () => {
    serve(["settings.manage"], {
      leave_year: "ethiopian",
      leave_year_changeable: true,
    });
    renderCard();

    const select = await screen.findByRole("combobox");
    expect(select).toHaveTextContent("Ethiopian year, Meskerem to Pagume");
    await waitFor(() => expect(select).toBeEnabled());
  });

  it("says why the year is fixed once leave has been used", async () => {
    serve(["settings.manage"], {
      leave_year: "gregorian",
      leave_year_changeable: false,
    });
    renderCard();

    expect(
      await screen.findByText(/so the leave year can no longer change/),
    ).toBeInTheDocument();
    expect(screen.getByRole("combobox")).toBeDisabled();
    expect(screen.getByRole("combobox")).toHaveTextContent(
      "Gregorian year, January to December",
    );
  });
});
