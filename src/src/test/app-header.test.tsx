import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { CalendarProvider, useCalendar } from "@/lib/calendar/calendar-context";
import { AppHeader } from "@/components/layouts/app-header";
import { AppLayoutProvider } from "@/components/layouts/layout-context";
import { TooltipProvider } from "@/components/ui/tooltip";

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
const setLocaleSpy = vi.hoisted(() => vi.fn());
vi.mock("@/lib/i18n/translations", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/i18n/translations")>()),
  setLocale: setLocaleSpy,
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
          {/* As app/providers.tsx wraps every page. */}
          <TooltipProvider>
            <AppHeader />
          </TooltipProvider>
        </AppLayoutProvider>
        <Shown />
      </CalendarProvider>
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  setLocaleSpy.mockClear();
  localStorage.setItem("locale", "en");
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

function capturePreferences() {
  const bodies: unknown[] = [];
  server.use(
    http.put("*/api/v1/profile/preferences", async ({ request }) => {
      bodies.push(await request.json());
      return HttpResponse.json({
        locale: "en",
        theme: "system",
        calendar: "gregorian",
      });
    }),
  );
  return bodies;
}

async function openMenu() {
  const user = userEvent.setup();
  renderHeader();
  await user.click(
    await screen.findByRole("button", { name: /language and calendar/i }),
  );
  await screen.findByRole("menu");
  return user;
}

describe("<AppHeader> language and calendar menu", () => {
  it("names the current language and calendar in words on the trigger", async () => {
    renderHeader();

    const trigger = await screen.findByRole("button", {
      name: /language and calendar/i,
    });
    expect(trigger).toHaveTextContent("English · Ethiopian");
  });

  it("exposes the current choices as checked radio items", async () => {
    await openMenu();

    expect(
      screen.getByRole("menuitemradio", { name: "English" }),
    ).toHaveAttribute("aria-checked", "true");
    expect(screen.getByRole("menuitemradio", { name: "አማርኛ" })).toHaveAttribute(
      "aria-checked",
      "false",
    );
    expect(
      screen.getByRole("menuitemradio", { name: /^Ethiopian/ }),
    ).toHaveAttribute("aria-checked", "true");
    expect(
      screen.getByRole("menuitemradio", { name: /^Gregorian/ }),
    ).toHaveAttribute("aria-checked", "false");
  });

  it("previews today's date in each calendar", async () => {
    vi.useFakeTimers({
      now: new Date("2026-10-30T09:00:00Z"),
      toFake: ["Date"],
    });
    try {
      await openMenu();

      expect(
        screen.getByRole("menuitemradio", { name: /^Ethiopian/ }),
      ).toHaveTextContent("Tikimt 20, 2019");
      expect(
        screen.getByRole("menuitemradio", { name: /^Gregorian/ }),
      ).toHaveTextContent("30 Oct 2026");
    } finally {
      vi.useRealTimers();
    }
  });

  it("applies a language at once and stores it on the user", async () => {
    const bodies = capturePreferences();
    const user = await openMenu();

    await user.click(screen.getByRole("menuitemradio", { name: "አማርኛ" }));

    expect(setLocaleSpy).toHaveBeenCalledWith("am");
    await waitFor(() => expect(bodies).toEqual([{ locale: "am" }]));
  });

  it("applies a calendar at once and stores it on the user", async () => {
    // Audit N33: the old toggle wrote this browser's localStorage and nothing
    // else, so the choice vanished on the next device and disagreed with the
    // profile setting.
    const bodies = capturePreferences();
    const user = await openMenu();

    await user.click(screen.getByRole("menuitemradio", { name: /^Gregorian/ }));

    expect(screen.getByLabelText("calendar in use")).toHaveTextContent(
      "gregorian",
    );
    await waitFor(() => expect(bodies).toEqual([{ calendar: "gregorian" }]));
  });

  it("no longer has the one-click calendar toggle", async () => {
    renderHeader();
    await screen.findByRole("button", { name: /language and calendar/i });

    expect(
      screen.queryByRole("button", { name: /toggle calendar/i }),
    ).not.toBeInTheDocument();
    expect(screen.queryByText("GC")).not.toBeInTheDocument();
    expect(screen.queryByText("EC")).not.toBeInTheDocument();
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
