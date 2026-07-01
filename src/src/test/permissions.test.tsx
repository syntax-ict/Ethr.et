import { describe, it, expect, vi } from "vitest";
import { renderHook } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";

// Mock useCurrentUser to return different roles
vi.mock("@/features/auth/api", () => ({
  useCurrentUser: vi.fn(),
}));

async function setupPermissionsTest(role: string) {
  const { useCurrentUser } = await import("@/features/auth/api");
  vi.mocked(useCurrentUser).mockReturnValue({ data: { role } } as any);

  const { usePermissions } = await import("@/lib/hooks/usePermissions");
  const qc = new QueryClient();
  const wrapper = ({ children }: { children: React.ReactNode }) => (
    <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  );

  return renderHook(() => usePermissions(), { wrapper });
}

describe("usePermissions", () => {
  it("super_admin can access everything", async () => {
    const { result } = await setupPermissionsTest("super_admin");
    expect(result.current.isSuperAdmin).toBe(true);
    expect(result.current.isTenantAdmin).toBe(true);
    expect(result.current.can.manageEmployees).toBe(true);
  });

  it("tenant_admin can manage employees but is not super_admin", async () => {
    const { result } = await setupPermissionsTest("tenant_admin");
    expect(result.current.isSuperAdmin).toBe(false);
    expect(result.current.isTenantAdmin).toBe(true);
    expect(result.current.can.manageEmployees).toBe(true);
  });

  it("employee cannot manage employees or view team", async () => {
    const { result } = await setupPermissionsTest("employee");
    expect(result.current.can.manageEmployees).toBe(false);
    expect(result.current.can.viewTeam).toBe(false);
    expect(result.current.isEmployee).toBe(true);
  });

  it("supervisor can view team but cannot manage employees", async () => {
    const { result } = await setupPermissionsTest("supervisor");
    expect(result.current.can.viewTeam).toBe(true);
    expect(result.current.can.manageEmployees).toBe(false);
  });

  it("hr_admin can manage employees", async () => {
    const { result } = await setupPermissionsTest("hr_admin");
    expect(result.current.can.manageEmployees).toBe(true);
    expect(result.current.isHrAdmin).toBe(true);
  });
});
