import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, renderHook, screen, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import PlatformAuditLogPage from "@/app/(dashboard)/admin/audit/page";
import { useAdminAuditLog } from "@/features/admin/api";
import { useCurrentUser } from "@/features/auth/api";

vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({ isSuperAdmin: true }),
}));
vi.mock("@/components/shared/role-gate", () => ({
  RoleGate: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

/**
 * The platform audit lists people from every organisation, so the API reads
 * it only for an operator with two-factor on (`RequirePlatformMfa`, A-Z review
 * H-11) and answers 403 otherwise. The console used to ask anyway, which left
 * a 403 in the browser console on /admin and /admin/audit for every operator
 * who had not enrolled yet (UX audit run 46153728500). It now asks only when
 * it can be answered, and the page says why it is closed.
 */
const EMPTY_PAGE = {
  data: [],
  meta: { current_page: 1, last_page: 1, per_page: 50, total: 0 },
  links: { next: null, prev: null },
};

let auditRequests = 0;

function signedInAs(mfaEnabled: boolean) {
  server.use(
    http.get("*/api/v1/auth/me", () =>
      HttpResponse.json({
        user: {
          public_id: "01HZOPERATOR",
          name: "Operator",
          role: "super_admin",
          mfa_enabled: mfaEnabled,
        },
        permissions: [],
        tenant: null,
      }),
    ),
    http.get("*/api/v1/admin/audit", () => {
      auditRequests += 1;
      return HttpResponse.json(EMPTY_PAGE);
    }),
  );
}

function wrapper({ children }: { children: React.ReactNode }) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

beforeEach(() => {
  auditRequests = 0;
});

describe("useAdminAuditLog", () => {
  it("asks nothing of an operator without two-factor", async () => {
    signedInAs(false);
    const { result } = renderHook(
      () => ({ audit: useAdminAuditLog(), me: useCurrentUser() }),
      { wrapper },
    );

    await waitFor(() => expect(result.current.me.isSuccess).toBe(true));
    expect(result.current.audit.fetchStatus).toBe("idle");
    expect(auditRequests).toBe(0);
  });

  it("asks once two-factor is on", async () => {
    signedInAs(true);
    const { result } = renderHook(() => useAdminAuditLog(), { wrapper });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(auditRequests).toBe(1);
  });
});

describe("/admin/audit", () => {
  it("says it needs two-factor and offers to turn it on, without asking", async () => {
    signedInAs(false);
    render(<PlatformAuditLogPage />, { wrapper });

    expect(
      await screen.findByText(/needs two-factor authentication to read/i),
    ).toBeInTheDocument();
    expect(screen.getByRole("link", { name: /enable now/i })).toHaveAttribute(
      "href",
      "/profile/security",
    );
    expect(auditRequests).toBe(0);
  });
});
