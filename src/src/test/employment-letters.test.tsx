import { describe, it, expect, vi } from "vitest";
import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { MyLettersCard } from "@/features/letters/components/my-letters-card";
import LetterRequestsPage from "@/app/(dashboard)/employees/letters/page";
import { CalendarProvider } from "@/lib/calendar/calendar-context";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

vi.mock("@/components/shared/role-gate", () => ({
  RoleGate: ({ children }: { children: ReactNode }) => <>{children}</>,
}));

/**
 * Employment letters (2026-10-20, blueprint ESS7): an employee asks, HR
 * issues or declines with a reason, and an issued letter downloads.
 */
const PENDING = {
  public_id: "01HZLET0000000000000000001",
  employee: { public_id: "01HZEMP0000000000000000001", name: "Selam Tesfaye" },
  purpose: "Bank loan application",
  addressed_to: null,
  include_salary: true,
  language: "en",
  status: "pending",
  reference: null,
  rejection_reason: null,
  decided_at: null,
  created_at: "2026-10-20T06:00:00Z",
};

function wrap(ui: ReactNode) {
  server.use(
    http.get("*/api/v1/auth/me", () =>
      HttpResponse.json({
        user: { public_id: "U1", role: "hr_admin" },
        permissions: [],
        tenant: { timezone: "Africa/Addis_Ababa" },
      }),
    ),
  );
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={queryClient}>
      <CalendarProvider>{ui}</CalendarProvider>
    </QueryClientProvider>,
  );
}

describe("<MyLettersCard>", () => {
  it("sends the request with what the employee chose", async () => {
    const sent: unknown[] = [];
    server.use(
      http.get("*/api/v1/profile/letters", () =>
        HttpResponse.json({ data: [] }),
      ),
      http.post("*/api/v1/profile/letters", async ({ request }) => {
        sent.push(await request.json());
        return HttpResponse.json(PENDING, { status: 201 });
      }),
    );
    const user = userEvent.setup();
    wrap(<MyLettersCard />);

    const button = await screen.findByRole("button", {
      name: "Request letter",
    });
    expect(button).toBeDisabled();
    await user.type(
      screen.getByLabelText("What is it for?"),
      "Bank loan application",
    );
    await user.click(screen.getByRole("checkbox"));
    await user.click(button);

    await vi.waitFor(() =>
      expect(sent).toEqual([
        {
          purpose: "Bank loan application",
          include_salary: true,
          language: "en",
        },
      ]),
    );
  });

  it("offers the download once a letter is issued", async () => {
    server.use(
      http.get("*/api/v1/profile/letters", () =>
        HttpResponse.json({
          data: [{ ...PENDING, status: "issued", reference: "EL-2026-0001" }],
        }),
      ),
    );
    wrap(<MyLettersCard />);

    expect(
      await screen.findByRole("button", { name: "Download" }),
    ).toBeInTheDocument();
    expect(screen.getByText("Issued")).toBeInTheDocument();
  });
});

describe("<LetterRequestsPage>", () => {
  it("issues a waiting request", async () => {
    const issued: string[] = [];
    server.use(
      http.get("*/api/v1/employees/letters", () =>
        HttpResponse.json({ data: [PENDING] }),
      ),
      http.post("*/api/v1/employees/letters/:id/issue", ({ params }) => {
        issued.push(String(params.id));
        return HttpResponse.json({ ...PENDING, status: "issued" });
      }),
    );
    const user = userEvent.setup();
    wrap(<LetterRequestsPage />);

    await user.click(await screen.findByRole("button", { name: "Issue" }));
    await vi.waitFor(() => expect(issued).toEqual([PENDING.public_id]));
  });

  it("declines only with a reason", async () => {
    const declined: unknown[] = [];
    server.use(
      http.get("*/api/v1/employees/letters", () =>
        HttpResponse.json({ data: [PENDING] }),
      ),
      http.post(
        "*/api/v1/employees/letters/:id/reject",
        async ({ request }) => {
          declined.push(await request.json());
          return HttpResponse.json({ ...PENDING, status: "rejected" });
        },
      ),
    );
    const user = userEvent.setup();
    wrap(<LetterRequestsPage />);

    await user.click(await screen.findByRole("button", { name: "Decline" }));
    const dialog = await screen.findByRole("dialog");
    const confirm = within(dialog).getByRole("button", { name: "Decline" });
    expect(confirm).toBeDisabled();
    await user.type(
      within(dialog).getByLabelText("Reason"),
      "Probation not complete",
    );
    await user.click(confirm);

    await vi.waitFor(() =>
      expect(declined).toEqual([{ reason: "Probation not complete" }]),
    );
  });
});
