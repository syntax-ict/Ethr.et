import { describe, it, expect, vi } from "vitest";
import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { UnmatchedBadgesCard } from "@/features/devices/components/unmatched-badges-card";
import { CalendarProvider } from "@/lib/calendar/calendar-context";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({
    hasPermission: (p: string) => p === "attendance.manage",
    can: {},
    role: "hr_admin",
  }),
}));

vi.mock("@/components/shared/employee-select", () => ({
  EmployeeSelect: ({ onChange }: { onChange: (id: string) => void }) => (
    <button
      type="button"
      onClick={() => onChange("01HZEMP0000000000000000001")}
    >
      Pick Selam
    </button>
  ),
}));

/**
 * Punches from a badge no employee matched are held and shown here. Assigning
 * the badge replays them; dismissing drops them (2026-10-20, blueprint AT3).
 */
const ROW = {
  device_public_id: "01HZDEV0000000000000000001",
  device_name: "Main gate",
  badge: "7777",
  punches: 2,
  first_seen: "2026-10-14 05:01:22",
  last_seen: "2026-10-14 14:05:00",
};

function renderCard(
  rows: unknown[],
  onPost: (path: string, body: unknown) => void,
) {
  server.use(
    http.get("*/api/v1/devices/unmatched-punches", () =>
      HttpResponse.json({ data: rows }),
    ),
    http.post(
      "*/api/v1/devices/unmatched-punches/:action",
      async ({ request, params }) => {
        onPost(String(params.action), await request.json());
        return HttpResponse.json(
          params.action === "assign"
            ? { replayed: 2, refused: 0 }
            : { dismissed: 2 },
        );
      },
    ),
    http.get("*/api/v1/auth/me", () =>
      HttpResponse.json({
        user: { public_id: "U1", role: "hr_admin" },
        permissions: ["attendance.manage"],
        tenant: { timezone: "Africa/Addis_Ababa" },
      }),
    ),
  );
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const Wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>
      <CalendarProvider>{children}</CalendarProvider>
    </QueryClientProvider>
  );
  return render(<UnmatchedBadgesCard />, { wrapper: Wrapper });
}

describe("<UnmatchedBadgesCard>", () => {
  it("assigns a held badge to the chosen employee", async () => {
    const posts: Array<[string, unknown]> = [];
    const user = userEvent.setup();
    renderCard([ROW], (path, body) => posts.push([path, body]));

    expect(await screen.findByText("Badge 7777")).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Pick Selam" }));
    await user.click(screen.getByRole("button", { name: "Assign" }));

    await vi.waitFor(() =>
      expect(posts).toEqual([
        [
          "assign",
          {
            device_public_id: ROW.device_public_id,
            badge: "7777",
            employee_public_id: "01HZEMP0000000000000000001",
          },
        ],
      ]),
    );
  });

  it("asks before dismissing a badge", async () => {
    const posts: Array<[string, unknown]> = [];
    const user = userEvent.setup();
    renderCard([ROW], (path, body) => posts.push([path, body]));

    await user.click(await screen.findByRole("button", { name: "Dismiss" }));
    const dialog = await screen.findByRole("dialog");
    expect(within(dialog).getByText("Dismiss badge 7777?")).toBeInTheDocument();
    expect(posts).toEqual([]);

    await user.click(within(dialog).getByRole("button", { name: "Dismiss" }));
    await vi.waitFor(() =>
      expect(posts).toEqual([
        ["dismiss", { device_public_id: ROW.device_public_id, badge: "7777" }],
      ]),
    );
  });

  it("shows nothing when no badge is waiting", async () => {
    const { container } = renderCard([], () => {});
    await new Promise((r) => setTimeout(r, 50));
    expect(container).toBeEmptyDOMElement();
  });
});
