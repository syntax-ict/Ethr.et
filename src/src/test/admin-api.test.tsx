import { describe, it, expect } from "vitest";
import { renderHook, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";

/**
 * Every hook in `features/admin/api` is gated on `enabled: isSuperAdmin`, so
 * that the page cannot fire privileged requests it will only get a 403 for
 * (see UX_PHASE_06-08.md P6-02). `usePermissions()` derives the role from
 * `GET /auth/me`, which means these tests have to establish an identity —
 * without one the queries are correctly disabled and never resolve.
 */
function mockIdentity(role: string) {
  server.use(
    http.get("*/api/v1/auth/me", () =>
      HttpResponse.json({
        user: { public_id: "01HZUSER0001", name: "Root", role },
        permissions: [],
        tenant: null,
      }),
    ),
  );
}

function createWrapper({ role = "super_admin" }: { role?: string } = {}) {
  mockIdentity(role);

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

describe("useAdminTenants", () => {
  it("fetches paginated tenant list", async () => {
    server.use(
      http.get("*/api/v1/admin/tenants", () =>
        HttpResponse.json({
          data: [
            {
              public_id: "01HZTENANT001",
              name: "Acme Corp",
              subdomain: "acme",
              status: "active",
              plan: "Professional",
              employee_count: 150,
              created_at: "2026-01-01T00:00:00Z",
            },
            {
              public_id: "01HZTENANT002",
              name: "Beta LLC",
              subdomain: "beta",
              status: "trial",
              plan: "Starter",
              employee_count: 10,
              created_at: "2026-06-01T00:00:00Z",
            },
          ],
          meta: {
            current_page: 1,
            last_page: 1,
            per_page: 25,
            total: 2,
          },
          links: { next: null, prev: null },
        }),
      ),
    );

    const { useAdminTenants } = await import("@/features/admin/api");
    const { result } = renderHook(() => useAdminTenants(), {
      wrapper: createWrapper(),
    });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data!.data).toHaveLength(2);
    expect(result.current.data!.data[0].subdomain).toBe("acme");
    expect(result.current.data!.data[1].status).toBe("trial");
  });
});

describe("useAdminRevenue", () => {
  it("fetches platform revenue metrics", async () => {
    server.use(
      http.get("*/api/v1/admin/revenue", () =>
        HttpResponse.json({
          mrr_cents: 4500000,
          total_tenants: 50,
          active_tenants: 42,
          trial_tenants: 8,
          churn_rate: 2.5,
        }),
      ),
    );

    const { useAdminRevenue } = await import("@/features/admin/api");
    const { result } = renderHook(() => useAdminRevenue(), {
      wrapper: createWrapper(),
    });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data!.mrr_cents).toBe(4500000);
    expect(result.current.data!.active_tenants).toBe(42);
  });
});

describe("useAdminHealth", () => {
  it("fetches system health status", async () => {
    server.use(
      http.get("*/api/v1/admin/health", () =>
        HttpResponse.json({
          services: {
            database: { status: "healthy", response_ms: 2 },
            cache: { status: "healthy", response_ms: 1 },
            storage: { status: "healthy", response_ms: 5 },
          },
          queue: {
            default: { depth: 0 },
            payroll: { depth: 3 },
          },
          failed_jobs: 0,
          resources: {
            php_memory_mb: 64,
            php_peak_memory_mb: 128,
            disk_free_gb: 50,
          },
        }),
      ),
    );

    const { useAdminHealth } = await import("@/features/admin/api");
    const { result } = renderHook(() => useAdminHealth(), {
      wrapper: createWrapper(),
    });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data!.services.database.status).toBe("healthy");
    expect(result.current.data!.failed_jobs).toBe(0);
    expect(result.current.data!.queue.payroll.depth).toBe(3);
  });
});

describe("useUpdateTenantStatus", () => {
  it("updates tenant status", async () => {
    server.use(
      http.put("*/api/v1/admin/tenants/:id/status", async ({ request }) => {
        const body = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({
          public_id: "01HZTENANT002",
          status: body.status,
        });
      }),
    );

    const { useUpdateTenantStatus } = await import("@/features/admin/api");
    const { result } = renderHook(() => useUpdateTenantStatus(), {
      wrapper: createWrapper(),
    });

    result.current.mutate({
      publicId: "01HZTENANT002",
      status: "suspended",
    });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data.status).toBe("suspended");
  });
});

describe("useExtendTrial", () => {
  it("extends a tenant trial period", async () => {
    server.use(
      http.post("*/api/v1/admin/tenants/:id/extend-trial", () =>
        HttpResponse.json({
          public_id: "01HZTENANT002",
          trial_ends_at: "2026-09-01T00:00:00Z",
          message: "Trial extended by 30 days",
        }),
      ),
    );

    const { useExtendTrial } = await import("@/features/admin/api");
    const { result } = renderHook(() => useExtendTrial(), {
      wrapper: createWrapper(),
    });

    result.current.mutate({ publicId: "01HZTENANT002", days: 30 });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data.trial_ends_at).toBeTruthy();
  });
});

describe("useFailedJobs", () => {
  it("fetches failed jobs list", async () => {
    server.use(
      http.get("*/api/v1/admin/failed-jobs", () =>
        HttpResponse.json({
          data: [
            {
              uuid: "a1b2c3d4",
              connection: "redis",
              queue: "payroll",
              payload: '{"job":"ProcessPayroll"}',
              exception: "Timeout after 30s",
              failed_at: "2026-07-24T10:00:00Z",
            },
          ],
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

    const { useFailedJobs } = await import("@/features/admin/api");
    const { result } = renderHook(() => useFailedJobs(), {
      wrapper: createWrapper(),
    });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data!.data).toHaveLength(1);
    expect(result.current.data!.data[0].queue).toBe("payroll");
  });
});

describe("super-admin gating", () => {
  /**
   * `RoleGate` only guards rendering; hooks run regardless. Before these
   * queries were gated, a tenant admin opening /admin fired four privileged
   * requests and collected four 403s — noise in the console and, more
   * importantly, authorisation failures in the server log indistinguishable
   * from probing. This asserts the request is never issued at all.
   */
  it("does not request admin endpoints for a non-super-admin", async () => {
    let requested = false;
    server.use(
      http.get("*/api/v1/admin/health", () => {
        requested = true;
        return HttpResponse.json({
          services: {},
          queue: {},
          failed_jobs: 0,
        });
      }),
    );

    const { useAdminHealth } = await import("@/features/admin/api");
    const { result } = renderHook(() => useAdminHealth(), {
      wrapper: createWrapper({ role: "tenant_admin" }),
    });

    // Give the identity query time to resolve, so this cannot pass merely
    // because nothing had happened yet.
    await waitFor(() => expect(result.current.fetchStatus).toBe("idle"));

    expect(requested).toBe(false);
    expect(result.current.isSuccess).toBe(false);
  });
});
