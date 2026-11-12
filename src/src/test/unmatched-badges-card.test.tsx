import { describe, it, expect, vi, beforeEach } from "vitest";
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

const granted = vi.hoisted(() => ({ list: ["attendance.manage"] }));

vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({
    hasPermission: (p: string) => granted.list.includes(p),
    can: {},
    role: "hr_admin",
  }),
}));

const saved = vi.hoisted(() => ({ files: [] as string[] }));
vi.mock("@/lib/utils/csv-export", async (original) => ({
  ...(await original<typeof import("@/lib/utils/csv-export")>()),
  saveBlob: (name: string) => saved.files.push(name),
}));

const built = vi.hoisted(() => ({ rows: [] as unknown[] }));
vi.mock("@/features/onboarding/v2/xlsx-grid", async (original) => ({
  ...(await original<typeof import("@/features/onboarding/v2/xlsx-grid")>()),
  buildTemplate: async (
    _spec: unknown,
    _calendar: unknown,
    _text: unknown,
    rows: unknown[],
  ) => {
    built.rows = rows;
    return new ArrayBuffer(8);
  },
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

// Each badge says what the server makes of it now (2026-11-12).
const SELAM = {
  employee_public_id: "01HZEMP0000000000000000009",
  employee_name: "Selam Tesfaye",
  score: 1,
  reasons: ["badge_number"],
};

describe("<UnmatchedBadgesCard> row states", () => {
  beforeEach(() => {
    granted.list = ["attendance.manage", "employee.create"];
    saved.files = [];
    built.rows = [];
  });

  // The Employees menu links to /devices#unmatched, and the card is not on
  // the page until its badges arrive, so the browser's jump finds nothing.
  it("scrolls to itself once it appears, when the address names it", async () => {
    const scrolled = vi.fn();
    Element.prototype.scrollIntoView = scrolled;
    window.history.replaceState(null, "", "/devices#unmatched");
    try {
      renderCard([ROW], () => {});
      expect(await screen.findByText("Badge 7777")).toBeInTheDocument();
      await vi.waitFor(() => expect(scrolled).toHaveBeenCalledTimes(1));
      expect(scrolled.mock.contexts[0]).toHaveProperty("id", "unmatched");
    } finally {
      window.history.replaceState(null, "", "/");
    }
  });

  it("names the holder of a badge the server now matches, and replays in one click", async () => {
    const posts: Array<[string, unknown]> = [];
    const user = userEvent.setup();
    renderCard(
      [
        {
          ...ROW,
          match: {
            outcome: "matched",
            employee_public_id: SELAM.employee_public_id,
            confidence: 1,
            candidates: [SELAM],
          },
        },
      ],
      (path, body) => posts.push([path, body]),
    );

    expect(
      await screen.findByText("This is Selam Tesfaye"),
    ).toBeInTheDocument();
    // No new employee for a badge someone already has.
    expect(
      screen.queryByRole("link", { name: "Add as a new employee" }),
    ).not.toBeInTheDocument();

    await user.click(
      screen.getByRole("button", { name: "Assign and replay 2 punches" }),
    );
    await vi.waitFor(() =>
      expect(posts).toEqual([
        [
          "assign",
          {
            device_public_id: ROW.device_public_id,
            badge: "7777",
            employee_public_id: SELAM.employee_public_id,
          },
        ],
      ]),
    );
  });

  it("offers a possible match for a person to confirm, never in one click", async () => {
    const posts: Array<[string, unknown]> = [];
    const user = userEvent.setup();
    renderCard(
      [
        {
          ...ROW,
          match: {
            outcome: "probable",
            employee_public_id: SELAM.employee_public_id,
            confidence: 0.7,
            candidates: [{ ...SELAM, score: 0.7 }],
          },
        },
      ],
      (path, body) => posts.push([path, body]),
    );

    await user.click(
      await screen.findByRole("button", { name: "Possibly Selam Tesfaye" }),
    );
    expect(screen.getByText(/70%/)).toBeInTheDocument();
    expect(posts).toEqual([]);
    expect(
      screen.queryByRole("button", { name: /Assign and replay/ }),
    ).not.toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "Assign" }));
    await vi.waitFor(() =>
      expect(posts).toEqual([
        [
          "assign",
          expect.objectContaining({
            employee_public_id: SELAM.employee_public_id,
          }),
        ],
      ]),
    );
  });

  it("links a badge nobody has to a new employee, with the badge and the day it was first held", async () => {
    renderCard(
      [
        {
          ...ROW,
          // 22:30 UTC is already the next day in Addis Ababa.
          first_seen: "2026-10-13 22:30:00",
          match: {
            outcome: "new",
            employee_public_id: null,
            confidence: 0,
            candidates: [],
          },
        },
      ],
      () => {},
    );

    const link = await screen.findByRole("link", {
      name: "Add as a new employee",
    });
    const href = new URL(link.getAttribute("href")!, "http://x");
    expect(href.pathname).toBe("/employees/new");
    expect(href.searchParams.get("badge")).toBe("7777");
    expect(href.searchParams.get("device")).toBe(ROW.device_public_id);
    expect(href.searchParams.get("hire_date")).toBe("2026-10-14");
  });

  it("says so instead of offering a form for a badge that cannot be a badge number", async () => {
    renderCard(
      [
        {
          ...ROW,
          badge: "card#4411/ext",
          match: {
            outcome: "new",
            employee_public_id: null,
            confidence: 0,
            candidates: [],
          },
        },
      ],
      () => {},
    );

    expect(
      await screen.findByText(/cannot be saved as a badge number/),
    ).toBeInTheDocument();
    expect(
      screen.queryByRole("link", { name: "Add as a new employee" }),
    ).not.toBeInTheDocument();
  });

  it("does not offer a new employee to someone who cannot add one", async () => {
    granted.list = ["attendance.manage"];
    renderCard(
      [
        {
          ...ROW,
          match: {
            outcome: "new",
            employee_public_id: null,
            confidence: 0,
            candidates: [],
          },
        },
      ],
      () => {},
    );

    expect(await screen.findByText("Badge 7777")).toBeInTheDocument();
    expect(
      screen.queryByRole("link", { name: "Add as a new employee" }),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: "Download these badges as a list" }),
    ).not.toBeInTheDocument();
  });

  it("downloads the employee template with one row per badge that still needs a person", async () => {
    const user = userEvent.setup();
    server.use(
      http.post("*/api/v1/employees/import/template", () =>
        HttpResponse.json({
          headers: ["name", "hire_date", "badge_number"],
          required: ["name"],
          date_columns: ["hire_date"],
          text_columns: ["badge_number"],
          lists: {},
        }),
      ),
    );
    const matched = {
      ...ROW,
      badge: "1001",
      match: {
        outcome: "matched",
        employee_public_id: SELAM.employee_public_id,
        confidence: 1,
        candidates: [SELAM],
      },
    };
    renderCard([ROW, { ...ROW, badge: "8888" }, matched], () => {});

    await user.click(
      await screen.findByRole("button", {
        name: "Download these badges as a list",
      }),
    );

    await vi.waitFor(() =>
      expect(saved.files).toEqual(["ethr-unrecognised-badges.xlsx"]),
    );
    expect(built.rows).toEqual([
      { badge_number: "7777" },
      { badge_number: "8888" },
    ]);
  });
});
