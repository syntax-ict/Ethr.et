import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { CalendarProvider, useCalendar } from "@/lib/calendar/calendar-context";
import { AppHeader } from "@/components/layouts/app-header";
import { AppLayoutProvider } from "@/components/layouts/layout-context";

vi.mock("next/navigation", () => ({
  usePathname: () => "/dashboard",
  useRouter: () => ({ push: vi.fn(), replace: vi.fn(), back: vi.fn() }),
  useSearchParams: () => new URLSearchParams(),
}));
vi.mock("next-themes", () => ({
  useTheme: () => ({ theme: "system", setTheme: vi.fn() }),
}));
// Polls its own endpoint; not what is under test.
vi.mock("@/features/notifications/components/notification-bell", () => ({
  NotificationBell: () => null,
}));

const LONG_NAME = "Abay Textile and Garment Manufacturing Share Company";

function Shown() {
  const { calendar } = useCalendar();
  return <output aria-label="calendar in use">{calendar}</output>;
}

function renderHeader() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <CalendarProvider>
        <AppLayoutProvider>
          <AppHeader />
        </AppLayoutProvider>
        <Shown />
      </CalendarProvider>
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  localStorage.setItem("ethr.calendar", "ethiopian");
  server.use(
    http.get("*/api/v1/auth/me", () =>
      HttpResponse.json({
        user: {
          public_id: "U1",
          role: "employee",
          preferences: { locale: "en", theme: "system", calendar: "ethiopian" },
        },
        tenant: { public_id: "T1", name: LONG_NAME },
        permissions: [],
      }),
    ),
  );
});

describe("<AppHeader> calendar toggle", () => {
  it("stores the choice on the user as well as applying it", async () => {
    // Audit N33: the toggle wrote this browser's localStorage and nothing
    // else, so the choice vanished on the next device and disagreed with the
    // profile setting.
    let body: unknown = null;
    server.use(
      http.put("*/api/v1/profile/preferences", async ({ request }) => {
        body = await request.json();
        return HttpResponse.json({
          locale: "en",
          theme: "system",
          calendar: "gregorian",
        });
      }),
    );
    const user = userEvent.setup();
    renderHeader();

    await user.click(await screen.findByTitle(/click for Gregorian/i));

    expect(screen.getByLabelText("calendar in use")).toHaveTextContent(
      "gregorian",
    );
    await waitFor(() => expect(body).toEqual({ calendar: "gregorian" }));
  });
});

describe("<AppHeader> organisation name", () => {
  it("keeps a long name to one truncated line, with the full name as its title", async () => {
    // Audit N34: at phone width the name wrapped over its own logo and crowded
    // the header icons.
    renderHeader();

    const name = await screen.findByText(LONG_NAME);
    expect(name).toHaveClass("truncate");
    expect(name).toHaveAttribute("title", LONG_NAME);
    // A flex child only truncates when every flex ancestor up to the
    // constrained one may shrink below its content.
    expect(name.parentElement).toHaveClass("min-w-0");
    expect(name.parentElement?.parentElement).toHaveClass("min-w-0");
  });
});
