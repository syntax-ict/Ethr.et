import { describe, it, expect, vi } from "vitest";
import { renderHook } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";

// Mock the /auth/me selectors so each case can pin a role and its resolved
// permission set independently — that pairing is what the hook has to get right.
vi.mock("@/features/auth/api", () => ({
  useCurrentUser: vi.fn(),
  useCurrentPermissions: vi.fn(),
}));

async function setupPermissionsTest(role: string, permissions: string[] = []) {
  const { useCurrentUser, useCurrentPermissions } =
    await import("@/features/auth/api");
  vi.mocked(useCurrentUser).mockReturnValue({
    data: { role },
  } as unknown as ReturnType<typeof useCurrentUser>);
  vi.mocked(useCurrentPermissions).mockReturnValue({
    data: permissions,
  } as unknown as ReturnType<typeof useCurrentPermissions>);

  const { usePermissions } = await import("@/lib/hooks/usePermissions");
  const qc = new QueryClient();
  const wrapper = ({ children }: { children: React.ReactNode }) => (
    <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  );

  return renderHook(() => usePermissions(), { wrapper });
}

describe("usePermissions", () => {
  it("super_admin holds the full catalogue", async () => {
    const { result } = await setupPermissionsTest("super_admin", [
      "employee.create",
      "admin.manage",
      "settings.manage",
    ]);
    expect(result.current.isSuperAdmin).toBe(true);
    expect(result.current.isTenantAdmin).toBe(true);
    expect(result.current.can.manageEmployees).toBe(true);
    expect(result.current.can.viewAdminConsole).toBe(true);
  });

  it("tenant_admin can manage employees but is not super_admin", async () => {
    const { result } = await setupPermissionsTest("tenant_admin", [
      "employee.create",
      "settings.manage",
    ]);
    expect(result.current.isSuperAdmin).toBe(false);
    expect(result.current.isTenantAdmin).toBe(true);
    expect(result.current.can.manageEmployees).toBe(true);
    // admin.manage is a platform ability tenant_admin does not hold.
    expect(result.current.can.viewAdminConsole).toBe(false);
  });

  it("employee cannot manage employees or view team", async () => {
    const { result } = await setupPermissionsTest("employee", [
      "attendance.checkIn",
      "profile.view",
    ]);
    expect(result.current.can.manageEmployees).toBe(false);
    expect(result.current.can.viewTeam).toBe(false);
    expect(result.current.isEmployee).toBe(true);
  });

  it("supervisor can view team but cannot manage employees", async () => {
    const { result } = await setupPermissionsTest("supervisor", [
      "attendance.viewTeam",
      "leave.approve",
    ]);
    expect(result.current.can.viewTeam).toBe(true);
    expect(result.current.can.approveLeave).toBe(true);
    expect(result.current.can.manageEmployees).toBe(false);
  });

  it("hr_admin can manage employees", async () => {
    const { result } = await setupPermissionsTest("hr_admin", [
      "employee.create",
      "report.generate",
    ]);
    expect(result.current.can.manageEmployees).toBe(true);
    expect(result.current.isHrAdmin).toBe(true);
  });

  it("grants a custom role its own abilities regardless of base role level", async () => {
    // The regression this guards: a custom role sitting on the `employee` base
    // role. Deriving `can` from the role hierarchy hid UI the API would allow.
    const { result } = await setupPermissionsTest("employee", [
      "employee.create",
      "settings.manage",
    ]);

    expect(result.current.can.manageEmployees).toBe(true);
    expect(result.current.can.manageSettings).toBe(true);
    // Identity still reflects the base role.
    expect(result.current.isEmployee).toBe(true);
    expect(result.current.isTenantAdmin).toBe(false);
  });

  it("withholds abilities a custom role omits even at a high base role", async () => {
    const { result } = await setupPermissionsTest("tenant_admin", [
      "employee.view",
    ]);

    expect(result.current.can.manageEmployees).toBe(false);
    expect(result.current.can.manageSettings).toBe(false);
    expect(result.current.isTenantAdmin).toBe(true);
  });

  it("denies everything while /auth/me is still loading", async () => {
    const { useCurrentUser, useCurrentPermissions } =
      await import("@/features/auth/api");
    vi.mocked(useCurrentUser).mockReturnValue({
      data: undefined,
    } as unknown as ReturnType<typeof useCurrentUser>);
    vi.mocked(useCurrentPermissions).mockReturnValue({
      data: undefined,
    } as unknown as ReturnType<typeof useCurrentPermissions>);

    const { usePermissions } = await import("@/lib/hooks/usePermissions");
    const qc = new QueryClient();
    const wrapper = ({ children }: { children: React.ReactNode }) => (
      <QueryClientProvider client={qc}>{children}</QueryClientProvider>
    );
    const { result } = renderHook(() => usePermissions(), { wrapper });

    expect(result.current.permissions).toEqual([]);
    expect(result.current.can.manageEmployees).toBe(false);
    expect(result.current.hasPermission("employee.create")).toBe(false);
  });
});
