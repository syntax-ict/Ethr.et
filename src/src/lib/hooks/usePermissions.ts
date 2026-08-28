import { useCurrentPermissions, useCurrentUser } from "@/features/auth/api";

const ROLE_LEVELS: Record<string, number> = {
  super_admin: 100,
  tenant_admin: 90,
  hr_admin: 70,
  finance_admin: 70,
  dept_admin: 50,
  supervisor: 30,
  employee: 10,
};

/**
 * Ability backing each `can.*` flag. These are the same strings the Policies
 * check server-side, so the UI and the API agree — including for users on a
 * custom role, whose permission set does not follow the role hierarchy.
 */
const CAN_ABILITIES = {
  manageEmployees: "employee.create",
  recordPersonnelAction: "personnel_action.create",
  manageDisciplinaryCases: "disciplinary_case.manage",
  manageRetirementCases: "retirement_case.manage",
  viewTeam: "attendance.viewTeam",
  viewAttendanceConflicts: "attendance.viewConflicts",
  resolveAttendanceConflicts: "attendance.resolveConflicts",
  processPayroll: "payroll.process",
  viewPayrollRuns: "payroll.viewAll",
  approveLeave: "leave.approve",
  manageLeaveTypes: "leave.manageTypes",
  manageOrg: "org.create",
  viewReports: "report.generate",
  viewExecutiveDashboard: "dashboard.executive",
  manageSettings: "settings.manage",
  manageApiKeys: "apikey.manage",
  manageWebhooks: "webhook.manage",
  viewAdminConsole: "admin.manage",
  // Regional Manager / Operations persona: executive-style dashboard, forced
  // to the caller's own branch server-side (viewExecutiveDashboard above is
  // the unrestricted CEO / HR Director / Finance Director persona).
  viewRegionalDashboard: "dashboard.regional",
} as const;

type CanFlag = keyof typeof CAN_ABILITIES;

export function usePermissions() {
  const { data: user } = useCurrentUser();
  const { data: permissions } = useCurrentPermissions();

  const role = user?.role ?? "employee";
  const level = ROLE_LEVELS[role] ?? 10;
  const granted = permissions ?? [];

  function hasPermission(ability: string): boolean {
    return granted.includes(ability);
  }

  // Role helpers answer "who is this user", not "what may they do". Anything
  // gating an action belongs in `can` so custom roles resolve correctly.
  function isAtLeast(minRole: string): boolean {
    return level >= (ROLE_LEVELS[minRole] ?? 999);
  }

  function hasRole(...roles: string[]): boolean {
    return roles.includes(role);
  }

  const can = Object.fromEntries(
    Object.entries(CAN_ABILITIES).map(([flag, ability]) => [
      flag,
      hasPermission(ability),
    ]),
  ) as Record<CanFlag, boolean>;

  return {
    role,
    level,
    permissions: granted,
    hasPermission,
    isAtLeast,
    hasRole,
    isSuperAdmin: role === "super_admin",
    isTenantAdmin: isAtLeast("tenant_admin"),
    isHrAdmin: isAtLeast("hr_admin"),
    isFinanceAdmin: hasRole("finance_admin", "tenant_admin", "super_admin"),
    isSupervisor: isAtLeast("supervisor"),
    isEmployee: role === "employee",
    can,
  };
}
