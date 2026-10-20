import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import NotificationsPage from "@/app/(dashboard)/notifications/page";
import { NotificationBell } from "@/features/notifications/components/notification-bell";

const push = vi.fn();
vi.mock("next/navigation", () => ({
  useRouter: () => ({ push, replace: vi.fn(), back: vi.fn() }),
}));

beforeEach(() => push.mockClear());

/**
 * A notification opens what it is about. Until 2026-10-20 clicking one only
 * marked it read (blueprint assessment N8). The server stores an in-app
 * path; anything that is not one is never followed.
 */
const NOTIFICATIONS = {
  data: [
    {
      id: "9d1c0f6e-0000-4000-8000-000000000001",
      type: "LeaveApprovedNotification",
      data: { message: "Your leave request was approved.", url: "/leave" },
      read_at: null,
      created_at: "2026-10-20T06:00:00.000000Z",
    },
    {
      id: "9d1c0f6e-0000-4000-8000-000000000002",
      type: "SystemAlertNotification",
      data: { message: "Elsewhere", url: "https://example.com/phish" },
      read_at: null,
      created_at: "2026-10-20T05:00:00.000000Z",
    },
  ],
  meta: { current_page: 1, last_page: 1, per_page: 25, total: 2 },
  links: { first: null, last: null, prev: null, next: null },
};

function renderWith(ui: ReactNode) {
  server.use(
    http.get("*/api/v1/notifications", () => HttpResponse.json(NOTIFICATIONS)),
    http.get("*/api/v1/notifications/unread-count", () =>
      HttpResponse.json({ count: 2 }),
    ),
    http.post("*/api/v1/notifications/:id/read", () =>
      HttpResponse.json({ ok: true }),
    ),
    http.put("*/api/v1/notifications/:id/read", () =>
      HttpResponse.json({ ok: true }),
    ),
    http.get("*/api/v1/auth/me", () =>
      HttpResponse.json({
        user: { public_id: "01HZUSER000000000000000001", role: "employee" },
        permissions: [],
        tenant: { timezone: "Africa/Addis_Ababa" },
      }),
    ),
  );
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={queryClient}>{ui}</QueryClientProvider>,
  );
}

describe("Opening a notification", () => {
  it("goes to what it is about from the notifications page", async () => {
    const user = userEvent.setup();
    renderWith(<NotificationsPage />);

    await user.click(
      await screen.findByText("Your leave request was approved."),
    );

    expect(push).toHaveBeenCalledWith("/leave");
  });

  it("goes to what it is about from the header bell", async () => {
    const user = userEvent.setup();
    renderWith(<NotificationBell />);

    await screen.findByText("2");
    await user.click(screen.getByRole("button", { name: /notifications/i }));
    await user.click(
      await screen.findByText("Your leave request was approved."),
    );

    expect(push).toHaveBeenCalledWith("/leave");
  });

  it("never follows a link that leaves the site", async () => {
    const user = userEvent.setup();
    renderWith(<NotificationsPage />);

    await user.click(await screen.findByText("Elsewhere"));

    expect(push).not.toHaveBeenCalled();
  });
});
