import { describe, it, expect } from "vitest";
import { renderHook, waitFor, act } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import {
  useAssignShift,
  useCreateShift,
  useShiftSchedule,
  useShifts,
} from "@/features/shifts/api";

function paged<T>(rows: T[], request: Request) {
  const url = new URL(request.url);
  const page = Number(url.searchParams.get("page") ?? 1);
  const perPage = Math.min(Number(url.searchParams.get("per_page") ?? 25), 100);
  return HttpResponse.json({
    data: rows.slice((page - 1) * perPage, page * perPage),
    meta: {
      current_page: page,
      last_page: Math.max(1, Math.ceil(rows.length / perPage)),
      per_page: perPage,
      total: rows.length,
    },
    links: {},
  });
}

function wrapper() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  function Wrapper({ children }: { children: ReactNode }) {
    return (
      <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
    );
  }
  return Wrapper;
}

describe("shift hooks", () => {
  it("useShifts returns every page", async () => {
    const rows = Array.from({ length: 205 }, (_, i) => ({
      public_id: `S${i + 1}`,
      name: `Shift ${i + 1}`,
    }));
    server.use(
      http.get("*/api/v1/shifts", ({ request }) => paged(rows, request)),
    );

    const { result } = renderHook(() => useShifts(), { wrapper: wrapper() });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data!.data).toHaveLength(205);
  });

  it("useShiftSchedule sends the window as filter[] parameters", async () => {
    let seen: URLSearchParams | null = null;
    server.use(
      http.get("*/api/v1/shifts/schedule", ({ request }) => {
        seen = new URL(request.url).searchParams;
        return paged([], request);
      }),
    );

    const { result } = renderHook(
      () =>
        useShiftSchedule({ date_from: "2026-10-01", date_to: "2026-10-31" }),
      { wrapper: wrapper() },
    );

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(seen!.get("filter[date_from]")).toBe("2026-10-01");
    expect(seen!.get("filter[date_to]")).toBe("2026-10-31");
  });

  it("a created shift refreshes the list", async () => {
    let rows = [{ public_id: "S1", name: "Morning" }];
    server.use(
      http.get("*/api/v1/shifts", ({ request }) => paged(rows, request)),
      http.post("*/api/v1/shifts", async ({ request }) => {
        const body = (await request.json()) as { name: string };
        rows = [...rows, { public_id: "S2", name: body.name }];
        return HttpResponse.json(rows[1], { status: 201 });
      }),
    );
    const Wrapper = wrapper();

    const { result } = renderHook(
      () => ({ list: useShifts(), create: useCreateShift() }),
      { wrapper: Wrapper },
    );
    await waitFor(() => expect(result.current.list.isSuccess).toBe(true));

    await act(() =>
      result.current.create.mutateAsync({
        name: "Night",
        start_time: "22:00",
        end_time: "06:00",
      }),
    );

    await waitFor(() =>
      expect(result.current.list.data!.data.map((s) => s.name)).toEqual([
        "Morning",
        "Night",
      ]),
    );
  });

  it("an assignment refreshes the schedule", async () => {
    let schedule: Record<string, string>[] = [];
    server.use(
      http.get("*/api/v1/shifts/schedule", ({ request }) =>
        paged(schedule, request),
      ),
      http.post("*/api/v1/shifts/assign", () => {
        schedule = [
          { assignable_type: "Employee", effective_from: "2026-10-01" },
        ];
        return HttpResponse.json(schedule[0], { status: 201 });
      }),
    );

    const { result } = renderHook(
      () => ({ schedule: useShiftSchedule(), assign: useAssignShift() }),
      { wrapper: wrapper() },
    );
    await waitFor(() => expect(result.current.schedule.isSuccess).toBe(true));
    expect(result.current.schedule.data!.data).toHaveLength(0);

    await act(() =>
      result.current.assign.mutateAsync({
        shift_public_id: "01HZSHIFT00000000000000001",
        assignable_type: "employee",
        assignable_public_id: "01HZEMPLOYEE0000000000001",
        effective_from: "2026-10-01",
      }),
    );

    await waitFor(() =>
      expect(result.current.schedule.data!.data).toHaveLength(1),
    );
  });
});
