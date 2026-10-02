import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import NotificationsPage from "@/app/(dashboard)/notifications/page";
import { NotificationBell } from "@/features/notifications/components/notification-bell";

/**
 * NotificationResource rows. Every database notification carries a `message`
 * except DashboardDigestNotification, whose toArray() sends `title` and
 * `branch` only.
 */
const NOTIFICATIONS = {
  data: [
    {
      id: "9d1c0f6e-0000-4000-8000-000000000001",
      type: "DashboardDigestNotification",
      data: { title: "Your weekly ETHR digest", branch: null },
      read_at: null,
      created_at: "2026-10-02T06:00:00.000000Z",
    },
  ],
  meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
  links: { first: null, last: null, prev: null, next: null },
};

function renderWith(ui: ReactNode) {
  server.use(
    http.get("*/api/v1/notifications", () => HttpResponse.json(NOTIFICATIONS)),
    http.get("*/api/v1/notifications/unread-count", () =>
      HttpResponse.json({ count: 1 }),
    ),
    // The page's relative times read the tenant's timezone from /auth/me.
    http.get("*/api/v1/auth/me", () =>
      HttpResponse.json({
        user: { public_id: "01HZUSER000000000000000001", role: "employee" },
        permissions: [],
        tenant: { timezone: "Africa/Addis_Ababa" },
      }),
    ),
  );
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={queryClient}>{ui}</QueryClientProvider>,
  );
}

/**
 * Both lists read only `data.message`, so a dashboard digest showed as its
 * PHP class name on the notifications page and as "New notification" in the
 * bell. Recent Activity already fell back to `title`; these now agree.
 */
describe("Notifications without a message", () => {
  it("shows a digest's title on the notifications page", async () => {
    renderWith(<NotificationsPage />);

    expect(
      await screen.findByText("Your weekly ETHR digest"),
    ).toBeInTheDocument();
    expect(
      screen.queryByText("DashboardDigestNotification"),
    ).not.toBeInTheDocument();
  });

  it("shows a digest's title in the header bell", async () => {
    const user = userEvent.setup();
    renderWith(<NotificationBell />);

    await screen.findByText("1");
    await user.click(screen.getByRole("button", { name: /notifications/i }));

    expect(
      await screen.findByText("Your weekly ETHR digest"),
    ).toBeInTheDocument();
    expect(screen.queryByText("New notification")).not.toBeInTheDocument();
  });
});
