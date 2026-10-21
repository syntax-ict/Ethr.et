import { describe, it, expect, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { NextSteps } from "@/features/dashboard/components/next-steps";

const STORAGE_KEY = "ethr.next-steps.dismissed.01HZUSER";

/**
 * The real usePermissions runs against what /auth/me says, so each row is
 * checked against the ability its endpoint enforces.
 */
function me(role: string, permissions: string[]) {
  server.use(
    http.get("*/api/v1/auth/me", () =>
      HttpResponse.json({
        user: { public_id: "01HZUSER", name: "Test", role },
        tenant: { public_id: "T1", name: "Demo" },
        permissions,
      }),
    ),
  );
}

function managerDashboard(total: number, absent = 0) {
  return http.get("*/api/v1/dashboard/manager", () =>
    HttpResponse.json({
      team_attendance: { present: 5, absent, late: 0 },
      pending_approvals: {
        leave: total,
        correction: 0,
        profile_update: 0,
        total,
      },
      team_on_leave: [],
      team_size: 6,
    }),
  );
}

function renderPanel() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <NextSteps />
    </QueryClientProvider>,
  );
}

describe("<NextSteps>", () => {
  beforeEach(() => {
    window.localStorage.removeItem(STORAGE_KEY);
  });

  it("renders nothing for an employee, and asks the API for nothing", async () => {
    const hits: string[] = [];
    server.use(
      http.get("*/api/v1/dashboard/manager", ({ request }) => {
        hits.push(request.url);
        return HttpResponse.json({});
      }),
    );
    me("employee", []);
    const { container } = renderPanel();

    // Give any stray request time to fire.
    await new Promise((r) => setTimeout(r, 200));
    expect(container).toBeEmptyDOMElement();
    expect(hits).toEqual([]);
  });

  it("tells an approver how many requests wait, why, and where to decide", async () => {
    me("supervisor", []);
    server.use(managerDashboard(3, 1));
    renderPanel();

    expect(
      await screen.findByText("3 requests are waiting for your decision"),
    ).toBeInTheDocument();
    expect(
      screen.getByText(/from the people you approve for/i),
    ).toBeInTheDocument();
    expect(screen.getByRole("link", { name: /decide/i })).toHaveAttribute(
      "href",
      "/approvals",
    );
    expect(
      screen.getByText("1 of your team is absent today"),
    ).toBeInTheDocument();
    expect(screen.getByRole("link", { name: /see team/i })).toHaveAttribute(
      "href",
      "/attendance/team",
    );
  });

  it("ranks open integrity flags above approvals, and shows no more than three", async () => {
    me("hr_admin", [
      "attendance.viewConflicts",
      "payroll.process",
      "device.viewAny",
      "attendance.manage",
    ]);
    server.use(
      managerDashboard(2, 4),
      http.get("*/api/v1/attendance/integrity/summary", () =>
        HttpResponse.json({ open: 7, accepted: 0, rejected: 0 }),
      ),
      http.get("*/api/v1/payroll/runs", () =>
        HttpResponse.json({
          data: [
            {
              public_id: "01HZRUN",
              period_label: "Tikimt 2019",
              status: "completed",
            },
          ],
          meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
          links: {},
        }),
      ),
      http.get("*/api/v1/devices/unmatched-punches", () =>
        HttpResponse.json({ data: [{ badge: "B1", punches: 2 }] }),
      ),
    );
    renderPanel();

    await screen.findByText("7 punches to review");
    const items = await screen.findAllByRole("listitem");
    expect(items.map((li) => li.textContent)).toEqual([
      expect.stringContaining("7 punches to review"),
      expect.stringContaining(
        "The Tikimt 2019 payroll run is waiting for approval",
      ),
      expect.stringContaining("2 requests are waiting for your decision"),
    ]);
    expect(screen.queryByText(/absent today/)).not.toBeInTheDocument();
  });

  it("puts a row away for this user and brings it back when its number changes", async () => {
    me("supervisor", []);
    server.use(managerDashboard(3));
    const user = userEvent.setup();
    const { unmount } = renderPanel();

    await screen.findByText("3 requests are waiting for your decision");
    await user.click(
      screen.getByRole("button", {
        name: "Put away: 3 requests are waiting for your decision",
      }),
    );
    await waitFor(() =>
      expect(screen.queryByText(/waiting for your decision/)).toBeNull(),
    );
    expect(JSON.parse(window.localStorage.getItem(STORAGE_KEY)!)).toEqual({
      approvals: "3",
    });

    // Two more arrive: the row is new again.
    unmount();
    server.use(managerDashboard(5));
    renderPanel();
    expect(
      await screen.findByText("5 requests are waiting for your decision"),
    ).toBeInTheDocument();
  });

  it("does not offer the integrity row to an approver who may not review flags", async () => {
    const hits: string[] = [];
    me("supervisor", []);
    server.use(
      managerDashboard(1),
      http.get("*/api/v1/attendance/integrity/summary", ({ request }) => {
        hits.push(request.url);
        return HttpResponse.json({ open: 9 });
      }),
    );
    renderPanel();

    await screen.findByText("1 request is waiting for your decision");
    expect(screen.queryByText(/punches to review/)).toBeNull();
    expect(hits).toEqual([]);
  });
});
