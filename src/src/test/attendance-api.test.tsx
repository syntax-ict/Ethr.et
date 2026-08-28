import { describe, it, expect } from "vitest";
import { renderHook, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";

function createWrapper() {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  // Named declaration rather than an anonymous arrow: react/display-name
  // needs a name to report, and an unnamed wrapper shows up as <Unknown> in
  // React DevTools and in test failure output.
  function TestQueryWrapper({ children }: { children: React.ReactNode }) {
    return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
  }

  return TestQueryWrapper;
}

const MOCK_ATTENDANCE = {
  public_id: "01HZATT001",
  employee: {
    public_id: "01HZEMP001",
    name: "Abebe Kebede",
    employee_code: "EMP-0001",
  },
  date: "2026-07-24",
  check_in: "2026-07-24T08:30:00Z",
  check_out: "2026-07-24T17:30:00Z",
  status: "present",
  source: "biometric",
  confidence_score: 95,
  worked_minutes: 480,
  overtime_minutes: 30,
};

describe("useMyAttendance", () => {
  it("fetches paginated attendance records", async () => {
    server.use(
      http.get("*/api/v1/attendance/my", () =>
        HttpResponse.json({
          data: [MOCK_ATTENDANCE],
          meta: {
            current_page: 1,
            last_page: 1,
            per_page: 25,
            total: 1,
          },
          links: { next: null, prev: null },
        }),
      ),
    );

    const { useMyAttendance } = await import("@/features/attendance/api");
    const { result } = renderHook(() => useMyAttendance({ page: 1 }), {
      wrapper: createWrapper(),
    });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data!.data).toHaveLength(1);
    expect(result.current.data!.data[0].status).toBe("present");
    expect(result.current.data!.data[0].confidence_score).toBe(95);
  });
});

describe("useAttendanceList", () => {
  it("fetches with filter parameters", async () => {
    server.use(
      http.get("*/api/v1/attendance", ({ request }) => {
        const url = new URL(request.url);
        const status = url.searchParams.get("filter[status]");
        const records = status === "late" ? [] : [MOCK_ATTENDANCE];
        return HttpResponse.json({
          data: records,
          meta: {
            current_page: 1,
            last_page: 1,
            per_page: 25,
            total: records.length,
          },
          links: { next: null, prev: null },
        });
      }),
    );

    const { useAttendanceList } = await import("@/features/attendance/api");
    const { result } = renderHook(
      () => useAttendanceList({ "filter[status]": "present" }),
      { wrapper: createWrapper() },
    );

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data!.data).toHaveLength(1);
  });
});

describe("useCheckIn", () => {
  it("sends check-in with idempotency key header", async () => {
    const capturedHeaders: Record<string, string> = {};
    server.use(
      http.post("*/api/v1/attendance/check-in", ({ request }) => {
        capturedHeaders["idempotency-key"] =
          request.headers.get("Idempotency-Key") ?? "";
        return HttpResponse.json({
          public_id: "01HZATT002",
          check_in: "2026-07-24T08:00:00Z",
          status: "present",
          source: "web",
          confidence_score: 80,
        });
      }),
    );

    const { useCheckIn } = await import("@/features/attendance/api");
    const { result } = renderHook(() => useCheckIn(), {
      wrapper: createWrapper(),
    });

    result.current.mutate({
      idempotency_key: "test-idem-key-001",
      source: "web",
    });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data.status).toBe("present");
    expect(capturedHeaders["idempotency-key"]).toBe("test-idem-key-001");
  });
});

describe("useCheckOut", () => {
  it("sends check-out with idempotency key", async () => {
    server.use(
      http.post("*/api/v1/attendance/check-out", () =>
        HttpResponse.json({
          public_id: "01HZATT002",
          check_out: "2026-07-24T17:00:00Z",
          worked_minutes: 480,
        }),
      ),
    );

    const { useCheckOut } = await import("@/features/attendance/api");
    const { result } = renderHook(() => useCheckOut(), {
      wrapper: createWrapper(),
    });

    result.current.mutate({ idempotency_key: "test-idem-key-002" });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data.worked_minutes).toBe(480);
  });
});

describe("useSubmitCorrection", () => {
  it("submits attendance correction", async () => {
    server.use(
      http.post("*/api/v1/attendance/corrections", async ({ request }) => {
        const body = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({
          public_id: "01HZCORR001",
          status: "pending",
          reason: body.reason,
          proposed_check_in: body.proposed_check_in,
        });
      }),
    );

    const { useSubmitCorrection } = await import("@/features/attendance/api");
    const { result } = renderHook(() => useSubmitCorrection(), {
      wrapper: createWrapper(),
    });

    result.current.mutate({
      attendance_record_public_id: "01HZATT001",
      proposed_check_in: "2026-07-24T08:00:00Z",
      reason: "Badge reader was offline",
    });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data.status).toBe("pending");
    expect(result.current.data.reason).toBe("Badge reader was offline");
  });
});

describe("useApproveCorrection", () => {
  it("approves a correction", async () => {
    server.use(
      http.put("*/api/v1/attendance/corrections/:id/approve", () =>
        HttpResponse.json({ status: "approved" }),
      ),
    );

    const { useApproveCorrection } = await import("@/features/attendance/api");
    const { result } = renderHook(() => useApproveCorrection(), {
      wrapper: createWrapper(),
    });

    result.current.mutate("01HZCORR001");

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data.status).toBe("approved");
  });
});

describe("useRejectCorrection", () => {
  it("rejects a correction with reason", async () => {
    server.use(
      http.put("*/api/v1/attendance/corrections/:id/reject", () =>
        HttpResponse.json({ status: "rejected" }),
      ),
    );

    const { useRejectCorrection } = await import("@/features/attendance/api");
    const { result } = renderHook(() => useRejectCorrection(), {
      wrapper: createWrapper(),
    });

    result.current.mutate({
      publicId: "01HZCORR001",
      reason: "No evidence provided",
    });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data.status).toBe("rejected");
  });
});

describe("useAttendanceIntelligence", () => {
  it("fetches intelligence dashboard data", async () => {
    // Mirrors the real AttendanceIntelligenceController::dashboard() shape —
    // the previous mock here invented a shape (`anomalies` as a bare number,
    // `suggestions`) the backend has never returned, which passed regardless
    // of whether the actual contract changed underneath it.
    server.use(
      http.get("*/api/v1/attendance/intelligence", () =>
        HttpResponse.json({
          date: "2026-08-18",
          anomalies: {
            count: 1,
            thresholds: {
              excessive_hours_minutes: 960,
              excessive_overtime_minutes: 240,
            },
            records: [
              {
                employee_public_id: "01HZANM001",
                employee_name: "Abebe Kebede",
                types: ["excessive_hours"],
                worked_minutes: 1140,
                overtime_minutes: 0,
              },
            ],
          },
          late_arrivals: { count: 0, records: [] },
          early_departures: { count: 0, records: [] },
          missing_punches: { count: 0, records: [] },
        }),
      ),
    );

    const { useAttendanceIntelligence } =
      await import("@/features/attendance/api");
    const { result } = renderHook(() => useAttendanceIntelligence(), {
      wrapper: createWrapper(),
    });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data.anomalies.count).toBe(1);
    expect(result.current.data.anomalies.records[0].types).toContain(
      "excessive_hours",
    );
  });
});
