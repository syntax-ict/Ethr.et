import { useCurrentUser } from '@/features/auth/api';

const ROLE_LEVELS: Record<string, number> = {
  super_admin: 100,
  tenant_admin: 90,
  hr_admin: 70,
  finance_admin: 70,
  dept_admin: 50,
  supervisor: 30,
  employee: 10,
};

export function usePermissions() {
  const { data: user } = useCurrentUser();
  const role = user?.role ?? 'employee';
  const level = ROLE_LEVELS[role] ?? 10;

  function isAtLeast(minRole: string): boolean {
    return level >= (ROLE_LEVELS[minRole] ?? 999);
  }

  function hasRole(...roles: string[]): boolean {
    return roles.includes(role);
  }

  return {
    role,
    level,
    isAtLeast,
    hasRole,
    isSuperAdmin: role === 'super_admin',
    isTenantAdmin: isAtLeast('tenant_admin'),
    isHrAdmin: isAtLeast('hr_admin'),
    isFinanceAdmin: hasRole('finance_admin', 'tenant_admin', 'super_admin'),
    isSupervisor: isAtLeast('supervisor'),
    isEmployee: role === 'employee',

    can: {
      manageEmployees: isAtLeast('hr_admin'),
      viewTeam: isAtLeast('supervisor'),
      processPayroll: hasRole('finance_admin', 'tenant_admin', 'super_admin'),
      viewPayrollRuns: hasRole('finance_admin', 'tenant_admin', 'super_admin'),
      approveLeave: isAtLeast('supervisor'),
      manageLeaveTypes: isAtLeast('hr_admin'),
      manageOrg: isAtLeast('hr_admin'),
      viewReports: isAtLeast('hr_admin'),
      viewExecutiveDashboard: isAtLeast('tenant_admin'),
      manageSettings: isAtLeast('tenant_admin'),
      manageApiKeys: isAtLeast('tenant_admin'),
      manageWebhooks: isAtLeast('tenant_admin'),
      viewAdminConsole: role === 'super_admin',
    },
  };
}
