import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { NotificationBell } from "@/features/notifications/components/notification-bell";

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), replace: vi.fn(), back: vi.fn() }),
}));

// Mock the API hooks
vi.mock("@/features/notifications/api", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/features/notifications/api")>()),
  useUnreadCount: vi.fn(() => ({ data: { count: 0 } })),
  useNotifications: vi.fn(() => ({ data: { data: [] } })),
  useMarkAsRead: vi.fn(() => ({ mutate: vi.fn() })),
  useMarkAllAsRead: vi.fn(() => ({ mutate: vi.fn() })),
}));

function wrapper({ children }: { children: React.ReactNode }) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

describe("NotificationBell", () => {
  it("renders the bell icon", () => {
    render(<NotificationBell />, { wrapper });
    expect(screen.getByRole("button")).toBeInTheDocument();
  });

  it("shows no badge when count is zero", () => {
    render(<NotificationBell />, { wrapper });
    expect(screen.queryByText("0")).not.toBeInTheDocument();
  });

  it("shows badge when there are unread notifications", async () => {
    const { useUnreadCount } = await import("@/features/notifications/api");
    vi.mocked(useUnreadCount).mockReturnValue({
      data: { count: 5 },
    } as unknown as ReturnType<typeof useUnreadCount>);
    render(<NotificationBell />, { wrapper });
    expect(screen.getByText("5")).toBeInTheDocument();
  });

  it("opens dropdown on click", async () => {
    render(<NotificationBell />, { wrapper });
    await userEvent.click(
      screen.getByRole("button", { name: /notifications/i }),
    );
    // The heading "Notifications" appears inside the dropdown
    expect(
      screen.getByRole("heading", { name: "Notifications" }),
    ).toBeInTheDocument();
  });

  it("shows empty state when no notifications", async () => {
    render(<NotificationBell />, { wrapper });
    await userEvent.click(
      screen.getByRole("button", { name: /notifications/i }),
    );
    expect(screen.getByText("No notifications")).toBeInTheDocument();
  });

  it('renders "View all notifications" link', async () => {
    render(<NotificationBell />, { wrapper });
    await userEvent.click(
      screen.getByRole("button", { name: /notifications/i }),
    );
    expect(
      screen.getByRole("link", { name: /view all notifications/i }),
    ).toBeInTheDocument();
  });
  // The first page was fetched on every page load for a list nobody had
  // asked to see (dashboard review, 2026-11-03, PR 11): the badge needs only
  // the count, so the list waits for the popover.
  it("asks for the list only once the dropdown is opened", async () => {
    const { useNotifications } = await import("@/features/notifications/api");
    vi.mocked(useNotifications).mockClear();
    render(<NotificationBell />, { wrapper });

    expect(vi.mocked(useNotifications)).toHaveBeenLastCalledWith(
      { page: 1 },
      false,
    );

    await userEvent.click(
      screen.getByRole("button", { name: /notifications/i }),
    );

    expect(vi.mocked(useNotifications)).toHaveBeenLastCalledWith(
      { page: 1 },
      true,
    );
  });
});
