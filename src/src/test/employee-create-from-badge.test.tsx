import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { buildEmployee } from "./msw/handlers";
import { CalendarProvider } from "@/lib/calendar/calendar-context";
import NewEmployeePage from "@/app/(dashboard)/employees/new/page";

/**
 * A badge a terminal sent that nobody has, opened from Unrecognised badges
 * (2026-11-12): the form arrives with the badge and the day it was first
 * held, and saving gives the badge to the new person, which records its
 * held punches through the existing assign endpoint.
 */
const router = vi.hoisted(() => ({ push: vi.fn(), back: vi.fn() }));
const search = vi.hoisted(() => ({
  params: "badge=7777&device=01HZDEV0000000000000000001&hire_date=2026-10-14",
}));

vi.mock("next/navigation", () => ({
  useRouter: () => router,
  useSearchParams: () => new URLSearchParams(search.params),
}));

const toasts = vi.hoisted(() => ({
  success: vi.fn(),
  error: vi.fn(),
  info: vi.fn(),
  warning: vi.fn(),
}));
vi.mock("sonner", () => ({ toast: toasts }));

function paginated<T>(rows: T[]) {
  return {
    data: rows,
    meta: {
      current_page: 1,
      last_page: 1,
      per_page: 100,
      total: rows.length,
      from: 1,
      to: rows.length,
    },
    links: { first: "", last: "", prev: null, next: null },
  };
}

const HELD = {
  device_public_id: "01HZDEV0000000000000000001",
  device_name: "Main gate",
  badge: "7777",
  punches: 12,
  first_seen: "2026-10-14 05:01:22",
  last_seen: "2026-10-20 14:05:00",
  match: null,
};

function renderPage(permissions: string[]) {
  localStorage.setItem("ethr.calendar", "gregorian");
  server.use(
    http.get("*/api/v1/auth/me", () =>
      HttpResponse.json({
        user: { public_id: "U1", role: "hr_admin" },
        permissions,
        tenant: { timezone: "Africa/Addis_Ababa" },
      }),
    ),
    http.get("*/api/v1/employees/next-code", () =>
      HttpResponse.json({ employee_code: "EMP-0042", format: "EMP-{SEQ:4}" }),
    ),
    http.get("*/api/v1/organization/:kind", () =>
      HttpResponse.json(paginated([])),
    ),
    http.get("*/api/v1/devices/unmatched-punches", () =>
      HttpResponse.json({ data: [HELD] }),
    ),
  );

  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={qc}>
      <CalendarProvider>
        <NewEmployeePage />
      </CalendarProvider>
    </QueryClientProvider>,
  );
}

const ALL = [
  "employee.create",
  "attendance.manage",
  "device.viewAny",
  "device.view",
];

describe("New employee from an unrecognised badge", () => {
  beforeEach(() => {
    search.params =
      "badge=7777&device=01HZDEV0000000000000000001&hire_date=2026-10-14";
    Object.values(toasts).forEach((fn) => fn.mockClear());
    router.push.mockClear();
  });

  it("arrives with the badge and the day it was first held, and says what saving does", async () => {
    renderPage(ALL);

    expect(
      await screen.findByText(
        "Badge 7777 from Main gate, with 12 punches held.",
        { exact: false },
      ),
    ).toBeInTheDocument();
    expect(
      screen.getByText(/recorded for this person when you save/),
    ).toBeInTheDocument();
    expect(document.getElementById("badge_number")).toHaveValue("7777");
    expect(document.getElementById("hire_date")).toHaveValue("2026-10-14");
  }, 45_000);

  it("gives the badge to the person just added, which replays its punches", async () => {
    let created: Record<string, unknown> | undefined;
    let assigned: Record<string, unknown> | undefined;
    const employee = buildEmployee();
    server.use(
      http.post("*/api/v1/employees", async ({ request }) => {
        created = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json(employee, { status: 201 });
      }),
      http.post(
        "*/api/v1/devices/unmatched-punches/assign",
        async ({ request }) => {
          assigned = (await request.json()) as Record<string, unknown>;
          return HttpResponse.json({ replayed: 12, refused: 0 });
        },
      ),
    );
    const { container } = renderPage(ALL);

    await screen.findByText(/with 12 punches held/);
    fireEvent.change(screen.getByLabelText(/Full Name/), {
      target: { value: "Dawit Bekele" },
    });
    fireEvent.submit(container.querySelector("form")!);

    await waitFor(() => expect(assigned).toBeDefined());
    expect(created).toMatchObject({
      name: "Dawit Bekele",
      badge_number: "7777",
      hire_date: "2026-10-14",
    });
    expect(assigned).toEqual({
      device_public_id: "01HZDEV0000000000000000001",
      badge: "7777",
      employee_public_id: employee.public_id,
    });
    await waitFor(() =>
      expect(toasts.success).toHaveBeenCalledWith(
        "12 held punches were recorded for them.",
      ),
    );
    expect(router.push).toHaveBeenCalledWith(
      `/employees/${employee.public_id}`,
    );
  }, 45_000);

  it("replays nothing when the badge was changed before saving", async () => {
    let assigned = false;
    server.use(
      http.post("*/api/v1/employees", () =>
        HttpResponse.json(buildEmployee(), { status: 201 }),
      ),
      http.post("*/api/v1/devices/unmatched-punches/assign", () => {
        assigned = true;
        return HttpResponse.json({ replayed: 0, refused: 0 });
      }),
    );
    const { container } = renderPage(ALL);

    await screen.findByText(/with 12 punches held/);
    fireEvent.change(screen.getByLabelText(/Full Name/), {
      target: { value: "Dawit Bekele" },
    });
    fireEvent.change(document.getElementById("badge_number")!, {
      target: { value: "7778" },
    });
    fireEvent.submit(container.querySelector("form")!);

    await waitFor(() => expect(router.push).toHaveBeenCalled());
    expect(assigned).toBe(false);
  }, 45_000);

  it("keeps the employee and says where the punches are when the replay fails", async () => {
    server.use(
      http.post("*/api/v1/employees", () =>
        HttpResponse.json(buildEmployee(), { status: 201 }),
      ),
      http.post("*/api/v1/devices/unmatched-punches/assign", () =>
        HttpResponse.json(
          { status: 500, title: "Server Error" },
          { status: 500 },
        ),
      ),
    );
    const { container } = renderPage(ALL);

    await screen.findByText(/with 12 punches held/);
    fireEvent.change(screen.getByLabelText(/Full Name/), {
      target: { value: "Dawit Bekele" },
    });
    fireEvent.submit(container.querySelector("form")!);

    await waitFor(() =>
      expect(toasts.error).toHaveBeenCalledWith(
        expect.stringContaining("still under Unrecognised badges"),
      ),
    );
    expect(router.push).toHaveBeenCalled();
  }, 45_000);

  it("adds the person without replaying for someone who cannot manage attendance", async () => {
    let assigned = false;
    server.use(
      http.post("*/api/v1/employees", () =>
        HttpResponse.json(buildEmployee(), { status: 201 }),
      ),
      http.post("*/api/v1/devices/unmatched-punches/assign", () => {
        assigned = true;
        return HttpResponse.json({ replayed: 0, refused: 0 });
      }),
    );
    const { container } = renderPage(["employee.create"]);

    expect(
      await screen.findByText(/can record its held punches from Devices/),
    ).toBeInTheDocument();
    fireEvent.change(screen.getByLabelText(/Full Name/), {
      target: { value: "Dawit Bekele" },
    });
    fireEvent.submit(container.querySelector("form")!);

    await waitFor(() => expect(router.push).toHaveBeenCalled());
    expect(assigned).toBe(false);
  }, 45_000);
});
