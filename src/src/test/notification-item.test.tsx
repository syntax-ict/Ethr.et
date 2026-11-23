import { describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import NotificationsPage from "@/app/(dashboard)/notifications/page";
import { NotificationBell } from "@/features/notifications/components/notification-bell";

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), replace: vi.fn(), back: vi.fn() }),
}));

const LIST = {
  data: [
    {
      id: "n-1",
      type: "LeaveRequestedNotification",
      data: { message: "Selam asked for leave.", url: "/leave" },
      read_at: null,
      created_at: "2026-10-20T06:00:00.000000Z",
    },
    {
      id: "n-2",
      type: "SomethingElse",
      data: {},
      read_at: "2026-10-20T05:00:00.000000Z",
      created_at: "2026-10-20T05:00:00.000000Z",
    },
  ],
  meta: { current_page: 1, last_page: 1, per_page: 25, total: 2 },
  links: { first: null, last: null, prev: null, next: null },
};

function renderWith(ui: React.ReactNode) {
  server.use(
    http.get("*/api/v1/notifications", () => HttpResponse.json(LIST)),
    http.get("*/api/v1/notifications/unread-count", () =>
      HttpResponse.json({ count: 1 }),
    ),
  );
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>{ui}</QueryClientProvider>,
  );
}

/** What each item row looks like: its classes and its words. */
function rows(names: RegExp[]) {
  return names.map((name) => {
    const row = screen.getByRole("button", { name });
    return { className: row.className, text: row.textContent };
  });
}

// The bell and the page each drew a notification by hand, with different
// fallback words and different markup. One component now draws both.
describe("NotificationItem", () => {
  const NAMES = [/selam asked for leave/i, /new notification/i];

  it("looks the same in the bell as on the page", async () => {
    const page = renderWith(<NotificationsPage />);
    await screen.findByText("Selam asked for leave.");
    const onPage = rows(NAMES);
    page.unmount();

    renderWith(<NotificationBell />);
    await userEvent.click(
      screen.getByRole("button", { name: /notifications/i }),
    );
    await screen.findByText("Selam asked for leave.");
    const inBell = rows(NAMES);

    expect(inBell).toEqual(onPage);
  });

  it("says one thing when a notification has no message", async () => {
    renderWith(<NotificationsPage />);

    expect(await screen.findByText("New notification")).toBeInTheDocument();
    expect(screen.queryByText("SomethingElse")).not.toBeInTheDocument();
  });
});
