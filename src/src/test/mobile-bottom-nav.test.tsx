import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { MobileBottomNav } from "@/components/layouts/mobile-bottom-nav";

const usePathnameMock = vi.fn(() => "/dashboard");
vi.mock("next/navigation", () => ({
  usePathname: () => usePathnameMock(),
}));

function renderWithProviders() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={queryClient}>
      <MobileBottomNav />
    </QueryClientProvider>,
  );
}

describe("MobileBottomNav", () => {
  it("renders the four primary tabs plus a More tab", () => {
    renderWithProviders();

    expect(screen.getByRole("link", { name: /home/i })).toHaveAttribute(
      "href",
      "/dashboard",
    );
    expect(screen.getByRole("link", { name: /attendance/i })).toHaveAttribute(
      "href",
      "/attendance",
    );
    expect(screen.getByRole("link", { name: /leave/i })).toHaveAttribute(
      "href",
      "/leave",
    );
    expect(screen.getByRole("link", { name: /payslips/i })).toHaveAttribute(
      "href",
      "/payroll/payslips",
    );
    expect(screen.getByRole("button", { name: /more/i })).toBeInTheDocument();
  });

  it("highlights the tab matching the current path", () => {
    usePathnameMock.mockReturnValue("/attendance/corrections");
    renderWithProviders();

    expect(screen.getByRole("link", { name: /attendance/i })).toHaveClass(
      "text-interactive-primary",
    );
    expect(screen.getByRole("link", { name: /home/i })).not.toHaveClass(
      "text-interactive-primary",
    );
  });

  it("opens the More sheet with the full nav on click", async () => {
    server.use(
      http.get("*/api/v1/auth/me", () =>
        HttpResponse.json({
          user: {
            public_id: "01HZUSER00000000000000001",
            email: "test@example.com",
            role: "employee",
          },
        }),
      ),
    );
    usePathnameMock.mockReturnValue("/dashboard");
    const user = userEvent.setup();
    renderWithProviders();

    await user.click(screen.getByRole("button", { name: /more/i }));

    expect(screen.getByRole("button", { name: /close/i })).toBeInTheDocument();
  });
});

describe("MobileBottomNav for approvers", () => {
  it("swaps Payslips for Approvals with the pending count", async () => {
    server.use(
      http.get("*/api/v1/auth/me", () =>
        HttpResponse.json({
          user: { public_id: "U1", name: "Lead", role: "supervisor" },
          tenant: {
            public_id: "T1",
            name: "Demo",
            timezone: "Africa/Addis_Ababa",
          },
          permissions: ["attendance.viewTeam"],
        }),
      ),
      http.get("*/api/v1/dashboard/manager", () =>
        HttpResponse.json({ pending_approvals: { total: 4 } }),
      ),
    );
    renderWithProviders();

    expect(
      await screen.findByRole("link", { name: /approvals \(4\)/i }),
    ).toHaveAttribute("href", "/approvals");
    expect(
      screen.queryByRole("link", { name: /payslips/i }),
    ).not.toBeInTheDocument();
  });
});
