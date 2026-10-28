import { describe, it, expect, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { MigrationLogins } from "@/features/onboarding/v2/components/migration-logins";

/**
 * After a device migration (EMPLOYEE-INVITATIONS-PLAN, PR C): who cannot sign
 * in yet, one button that gives them a way in, and the owner's hire-date
 * warning (2026-10-28).
 */

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const BATCH = "01JBQ8Z6ZK3M9V2X7Y4W5T6R8S";
const INVITATIONS = "01JBQ8Z6ZK3M9V2X7Y4W5T6R9A";

function renderPanel(onContinue?: () => void) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <MigrationLogins batchId={BATCH} onContinue={onContinue} />
    </QueryClientProvider>,
  );
}

function me(permissions: string[]) {
  return http.get("*/auth/me", () =>
    HttpResponse.json({
      user: { public_id: "01JBQ8Z6ZK3M9V2X7Y4W5T6R80", role: "tenant_admin" },
      permissions,
      tenant: null,
    }),
  );
}

const LOGINS = {
  without_login: [
    {
      employee_public_id: "01JBQ8Z6ZK3M9V2X7Y4W5T6R81",
      name: "Abebe Kebede",
      email: "abebe@acme.et",
      phone: null,
      channel: "email",
      invited: false,
    },
    {
      employee_public_id: "01JBQ8Z6ZK3M9V2X7Y4W5T6R82",
      name: "Guest card",
      email: null,
      phone: null,
      channel: "in_person",
      invited: false,
    },
  ],
  hire_dates_defaulted: 2,
};

describe("<MigrationLogins>", () => {
  it("lists who cannot sign in, warns about hire dates, and sends one request for all of them", async () => {
    let posted: unknown = null;
    server.use(
      me(["users.invite", "employee.create"]),
      http.get(`*/api/v1/onboarding/migration/batches/${BATCH}/logins`, () =>
        HttpResponse.json(LOGINS),
      ),
      http.post("*/api/v1/employees/logins", async ({ request }) => {
        posted = await request.json();
        return HttpResponse.json(
          {
            message: "Queued",
            created: 1,
            active: 0,
            in_person: 1,
            email_in_use: 0,
            batch: INVITATIONS,
            queued: 1,
          },
          { status: 202 },
        );
      }),
      http.get(`*/api/v1/users/invitations/${INVITATIONS}`, () =>
        HttpResponse.json({
          batch: INVITATIONS,
          total: 1,
          queued: 1,
          sent: 0,
          failed: 0,
          skipped: 0,
          failures: [],
        }),
      ),
    );
    const onContinue = vi.fn();
    renderPanel(onContinue);

    expect(await screen.findByText("Abebe Kebede")).toBeInTheDocument();
    expect(screen.getByText("Check the hire dates")).toBeInTheDocument();
    expect(screen.getByText(/2 people were given today/)).toBeInTheDocument();
    expect(screen.getByText("In person")).toBeInTheDocument();

    await userEvent.click(
      await screen.findByRole("button", { name: "Send invitations" }),
    );

    await waitFor(() =>
      expect(posted).toEqual({
        employee_ids: [
          "01JBQ8Z6ZK3M9V2X7Y4W5T6R81",
          "01JBQ8Z6ZK3M9V2X7Y4W5T6R82",
        ],
      }),
    );
    expect(
      await screen.findByText(
        "Logins created: 1. Invitations queued: 1. To activate in person: 1.",
      ),
    ).toBeInTheDocument();
    expect(
      await screen.findByText("Invitations are being sent"),
    ).toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: "Send invitations" }),
    ).not.toBeInTheDocument();

    await userEvent.click(screen.getByRole("button", { name: "Continue" }));
    expect(onContinue).toHaveBeenCalledOnce();
  });

  it("offers no send button without permission to invite, and no continue outside onboarding", async () => {
    server.use(
      me(["employee.create"]),
      http.get(`*/api/v1/onboarding/migration/batches/${BATCH}/logins`, () =>
        HttpResponse.json(LOGINS),
      ),
    );
    renderPanel();

    expect(await screen.findByText("Abebe Kebede")).toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: "Send invitations" }),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: "Continue" }),
    ).not.toBeInTheDocument();
  });

  it("says when everyone can already sign in, and shows no warning when hire dates came from the source", async () => {
    server.use(
      me(["users.invite"]),
      http.get(`*/api/v1/onboarding/migration/batches/${BATCH}/logins`, () =>
        HttpResponse.json({ without_login: [], hire_dates_defaulted: 0 }),
      ),
    );
    renderPanel();

    expect(
      await screen.findByText("Everyone brought in can already sign in."),
    ).toBeInTheDocument();
    expect(screen.queryByText("Check the hire dates")).not.toBeInTheDocument();
  });
});
