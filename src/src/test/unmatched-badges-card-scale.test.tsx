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

vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({
    hasPermission: (p: string) => p === "attendance.manage",
    can: {},
    role: "hr_admin",
  }),
}));

/**
 * Habru Woreda, 2026-11-18: 83 held badges and 3,927 employees, and the
 * Devices page stopped answering clicks. Every row carried a picker that
 * loaded every employee and rendered each one as an option, even closed.
 * These rows use the real picker, against an organisation of 4,000.
 */
const EMPLOYEES = Array.from({ length: 4000 }, (_, i) => ({
  public_id: `01HZEMP${String(i).padStart(19, "0")}`,
  name: i === 1234 ? "Selam Tesfaye" : `Employee ${String(i).padStart(4, "0")}`,
  employee_code: `E-${String(i).padStart(4, "0")}`,
}));

const ROWS = Array.from({ length: 80 }, (_, i) => ({
  device_public_id: "01HZDEV0000000000000000001",
  device_name: "Main gate",
  badge: String(9001 + i),
  punches: 3,
  first_seen: "2026-11-14 05:01:22",
  last_seen: "2026-11-17 14:05:00",
  match: {
    outcome: "new",
    employee_public_id: null,
    confidence: 0,
    candidates: [],
  },
  ...(i % 5 === 0
    ? {
        name_on_device: EMPLOYEES[200 + i].name,
        name_matches: [
          {
            employee_public_id: EMPLOYEES[200 + i].public_id,
            employee_name: EMPLOYEES[200 + i].name,
            department: null,
          },
        ],
      }
    : {}),
}));

let employeeCalls: URL[] = [];
let posts: unknown[] = [];

function renderCard() {
  server.use(
    http.get("*/api/v1/devices/unmatched-punches", () =>
      HttpResponse.json({ data: ROWS }),
    ),
    http.get("*/api/v1/employees", ({ request }) => {
      const url = new URL(request.url);
      employeeCalls.push(url);
      const term = (url.searchParams.get("search") ?? "").toLowerCase();
      const perPage = Number(url.searchParams.get("per_page") ?? 25);
      const page = Number(url.searchParams.get("page") ?? 1);
      const hits = EMPLOYEES.filter(
        (e) =>
          term === "" ||
          e.name.toLowerCase().includes(term) ||
          e.employee_code.toLowerCase().includes(term),
      );
      const data = hits.slice((page - 1) * perPage, page * perPage);
      return HttpResponse.json({
        data,
        meta: {
          current_page: page,
          last_page: Math.max(1, Math.ceil(hits.length / perPage)),
          per_page: perPage,
          total: hits.length,
          from: data.length ? (page - 1) * perPage + 1 : 0,
          to: (page - 1) * perPage + data.length,
        },
        links: {},
      });
    }),
    http.post(
      "*/api/v1/devices/unmatched-punches/assign",
      async ({ request }) => {
        posts.push(await request.json());
        return HttpResponse.json({ replayed: 3, refused: 0, remaining: 0 });
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

function rowOf(badge: string): HTMLElement {
  const li = screen.getByText(`Badge ${badge}`).closest("li");
  if (!li) throw new Error(`no row for badge ${badge}`);
  return li;
}

describe("<UnmatchedBadgesCard> with 80 badges and 4,000 employees", () => {
  beforeEach(() => {
    employeeCalls = [];
    posts = [];
  });

  it("asks for no employee and renders no option until a picker is opened", async () => {
    renderCard();
    expect(await screen.findByText("Badge 9080")).toBeInTheDocument();
    // Long enough for any query a row starts on mount to go out.
    await new Promise((r) => setTimeout(r, 300));

    expect(screen.getAllByRole("combobox")).toHaveLength(80);
    expect(employeeCalls).toEqual([]);
    expect(document.querySelectorAll('[role="option"]')).toHaveLength(0);
  }, 30_000);

  it("opens one picker with one page of names, searches the server, and assigns the pick", async () => {
    const user = userEvent.setup();
    renderCard();
    const row = await vi.waitFor(() => rowOf("9002"));

    await user.click(within(row).getByRole("combobox"));
    const options = await screen.findAllByRole("option");
    expect(options.length).toBeLessThanOrEqual(20);
    expect(employeeCalls).toHaveLength(1);
    expect(employeeCalls[0].searchParams.get("per_page")).toBe("20");
    expect(employeeCalls[0].searchParams.get("page")).toBeNull();

    await user.type(
      screen.getByPlaceholderText("Search by name, email, or code..."),
      "Selam",
    );
    const selam = await screen.findByRole("option", { name: /Selam Tesfaye/ });
    expect(employeeCalls.at(-1)?.searchParams.get("search")).toBe("Selam");
    await user.click(selam);

    expect(within(row).getByRole("combobox")).toHaveTextContent(
      "Selam Tesfaye",
    );
    expect(document.querySelectorAll('[role="option"]')).toHaveLength(0);

    await user.click(within(row).getByRole("button", { name: "Assign" }));
    await vi.waitFor(() =>
      expect(posts).toEqual([
        expect.objectContaining({
          badge: "9002",
          employee_public_id: EMPLOYEES[1234].public_id,
        }),
      ]),
    );
  }, 30_000);

  it("fills a row's picker from its suggestion without asking the server, one click from Assign", async () => {
    const user = userEvent.setup();
    renderCard();
    const row = await vi.waitFor(() => rowOf("9006"));
    const suggested = EMPLOYEES[205];

    await user.click(
      within(row).getByRole("button", { name: `Possibly ${suggested.name}` }),
    );
    expect(within(row).getByRole("combobox")).toHaveTextContent(suggested.name);
    expect(employeeCalls).toEqual([]);

    await user.click(within(row).getByRole("button", { name: "Assign" }));
    await vi.waitFor(() =>
      expect(posts).toEqual([
        expect.objectContaining({
          badge: "9006",
          employee_public_id: suggested.public_id,
        }),
      ]),
    );
  }, 30_000);

  it("says so when the search fails, and asks again on Retry", async () => {
    const user = userEvent.setup();
    renderCard();
    let failing = true;
    server.use(
      http.get("*/api/v1/employees", () =>
        failing
          ? HttpResponse.json({ message: "Server Error" }, { status: 500 })
          : HttpResponse.json({
              data: [EMPLOYEES[7]],
              meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
              links: {},
            }),
      ),
    );
    const row = await vi.waitFor(() => rowOf("9002"));

    await user.click(within(row).getByRole("combobox"));
    const alert = await screen.findByRole("alert");
    expect(screen.queryAllByRole("option")).toHaveLength(0);

    failing = false;
    await user.click(within(alert).getByRole("button"));
    expect(
      await screen.findByRole("option", { name: /Employee 0007/ }),
    ).toBeInTheDocument();
  }, 30_000);
});
