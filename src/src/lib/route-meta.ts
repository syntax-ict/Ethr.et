export interface RouteMeta {
  label: string;
  description?: string;
  section?: string;
  parent?: { label: string; href: string };
}

/**
 * Breadcrumb + page-title metadata.
 *
 * `section` must name a section that actually exists in the sidebar
 * (see components/layouts/sidebar-nav.tsx). A breadcrumb naming a group the
 * user cannot find in the nav breaks the "you are here" contract it exists to
 * serve, so the two lists are kept deliberately in sync:
 * My Work · People · Operations · Finance · Admin.
 */
export const ROUTE_META: Record<string, RouteMeta> = {
  "/dashboard": {
    label: "Dashboard",
    description: "Overview of your workspace activity and key metrics",
  },
  "/setup/guided": {
    label: "Setup Wizard",
    description: "Configure your workspace step by step",
  },

  // ── My Work (employee self-service) ──────────────────────────────
  "/attendance": {
    section: "My Work",
    label: "My Attendance",
    description: "Your check-ins, check-outs, and timesheet history",
  },
  "/attendance/mobile": {
    section: "My Work",
    parent: { label: "My Attendance", href: "/attendance" },
    label: "Mobile Check-in",
    description: "Check in and out from your mobile device",
  },
  "/leave": {
    section: "My Work",
    label: "Leave",
    description: "Submit and track leave requests",
  },
  "/payroll/payslips": {
    section: "My Work",
    label: "My Payslips",
    description: "View and download your payslip history",
  },
  "/approvals": {
    section: "My Work",
    label: "Approvals",
    description: "Pending requests that need your attention",
  },
  "/announcements": {
    section: "My Work",
    label: "Announcements",
    description: "Company-wide announcements and notices",
  },

  // ── People ───────────────────────────────────────────────────────
  "/employees": {
    section: "People",
    label: "Employees",
    description: "Manage your organization's workforce",
  },
  "/employees/new": {
    section: "People",
    parent: { label: "Employees", href: "/employees" },
    label: "Add Employee",
    description: "Onboard a new team member",
  },
  "/employees/import": {
    section: "People",
    parent: { label: "Employees", href: "/employees" },
    label: "Import",
    description: "Bulk-import employees from a CSV file",
  },
  "/organization": {
    section: "People",
    label: "Organization",
    description: "Manage departments, branches, and positions",
  },
  "/directory": {
    section: "People",
    label: "Directory",
    description: "Browse the employee directory",
  },

  // ── Operations ───────────────────────────────────────────────────
  "/attendance/team": {
    section: "Operations",
    parent: { label: "Attendance", href: "/attendance" },
    label: "Team Attendance",
    description: "View and manage team attendance at a glance",
  },
  "/attendance/corrections": {
    section: "Operations",
    parent: { label: "Attendance", href: "/attendance" },
    label: "Corrections",
    description: "Review and approve attendance correction requests",
  },
  "/attendance/conflicts": {
    section: "Operations",
    parent: { label: "Attendance", href: "/attendance" },
    label: "Conflicts",
    description: "Review attendance records that disagree about the same day",
  },
  "/attendance/overtime": {
    section: "Operations",
    parent: { label: "Attendance", href: "/attendance" },
    label: "Overtime",
    description: "Track and manage overtime hours and approvals",
  },
  "/attendance/intelligence": {
    section: "Operations",
    parent: { label: "Attendance", href: "/attendance" },
    label: "Anomalies",
    description: "Attendance insights and anomaly detection",
  },
  "/attendance/import": {
    section: "Operations",
    parent: { label: "Attendance", href: "/attendance" },
    label: "Import Records",
    description: "Bulk-import attendance records from a file",
  },
  "/attendance/scan": {
    section: "Operations",
    parent: { label: "Attendance", href: "/attendance" },
    label: "Scan QR",
    description: "Scan employee QR codes for attendance",
  },
  "/attendance/qr": {
    section: "Operations",
    parent: { label: "Attendance", href: "/attendance" },
    label: "QR Codes",
    description: "Generate and manage attendance QR codes",
  },
  "/attendance/kiosks": {
    section: "Operations",
    parent: { label: "Attendance", href: "/attendance" },
    label: "Kiosks",
    description: "Configure self-service attendance kiosks",
  },
  "/shifts": {
    section: "Operations",
    label: "Shifts & Schedules",
    description: "Define and manage work shift schedules",
  },
  "/shifts/roster": {
    section: "Operations",
    parent: { label: "Shifts & Schedules", href: "/shifts" },
    label: "Roster",
    description: "View and manage shift rosters",
  },
  "/shifts/assignments": {
    section: "Operations",
    parent: { label: "Shifts & Schedules", href: "/shifts" },
    label: "Assignments",
    description: "Assign employees to shifts",
  },
  "/shifts/rotations": {
    section: "Operations",
    parent: { label: "Shifts & Schedules", href: "/shifts" },
    label: "Rotations",
    description: "Repeating multi-day shift patterns",
  },
  "/devices": {
    section: "Operations",
    label: "Devices",
    description: "Manage biometric and timeclock devices",
  },
  "/devices/dashboard": {
    section: "Operations",
    parent: { label: "Devices", href: "/devices" },
    label: "Device Status",
    description: "Device status and connectivity overview",
  },

  // ── Finance ──────────────────────────────────────────────────────
  "/payroll": {
    section: "Finance",
    label: "Payroll Runs",
    description: "Process payroll runs and view history",
  },
  "/payroll/loans": {
    section: "Finance",
    parent: { label: "Payroll Runs", href: "/payroll" },
    label: "Loans",
    description: "Track and manage employee loans and deductions",
  },
  "/payroll/cost-sharing": {
    section: "Finance",
    parent: { label: "Payroll Runs", href: "/payroll" },
    label: "Cost Sharing",
    description:
      "Track higher-education cost-sharing repayments deducted through payroll",
  },
  "/reports": {
    section: "Finance",
    label: "Reports",
    description: "Generate and download operational reports",
  },
  "/analytics": {
    section: "Finance",
    label: "Analytics",
    description: "Workforce analytics and trend dashboards",
  },

  // ── Admin ────────────────────────────────────────────────────────
  "/settings": {
    section: "Admin",
    label: "General Settings",
    description: "Organization, branding, and workspace configuration",
  },
  "/attendance/settings": {
    section: "Admin",
    parent: { label: "Settings", href: "/settings" },
    label: "Attendance Rules",
    description: "Configure attendance rules, grace periods, and policies",
  },
  "/settings/shifts": {
    section: "Admin",
    parent: { label: "Settings", href: "/settings" },
    label: "Shift Rules",
    description: "Configure shift templates and rotation rules",
  },
  "/settings/leave-types": {
    section: "Admin",
    parent: { label: "Settings", href: "/settings" },
    label: "Leave Types",
    description: "Define leave categories, accrual rules, and balances",
  },
  "/settings/holidays": {
    section: "Admin",
    parent: { label: "Settings", href: "/settings" },
    label: "Holidays",
    description: "Configure public holidays and calendar exceptions",
  },
  "/settings/payroll": {
    section: "Admin",
    parent: { label: "Settings", href: "/settings" },
    label: "Payroll Rules",
    description: "Allowances, income tax brackets, and overtime rates",
  },
  "/settings/roles": {
    section: "Admin",
    parent: { label: "Settings", href: "/settings" },
    label: "Roles & Permissions",
    description: "Manage custom roles and permission assignments",
  },
  "/settings/api-keys": {
    section: "Admin",
    parent: { label: "Settings", href: "/settings" },
    label: "API Keys",
    description: "Generate and manage API keys for external integrations",
  },
  "/settings/webhooks": {
    section: "Admin",
    parent: { label: "Settings", href: "/settings" },
    label: "Webhooks",
    description: "Configure outgoing webhook endpoints",
  },
  "/settings/accounting": {
    section: "Admin",
    parent: { label: "Settings", href: "/settings" },
    label: "Accounting",
    description: "Chart of accounts and journal entry mappings",
  },
  "/settings/notification-templates": {
    section: "Admin",
    parent: { label: "Settings", href: "/settings" },
    label: "Notification Templates",
    description: "Customize email and notification templates",
  },
  "/settings/audit-logs": {
    section: "Admin",
    parent: { label: "Settings", href: "/settings" },
    label: "Audit Log",
    description: "Immutable record of all system actions",
  },
  "/billing": {
    section: "Admin",
    label: "Billing",
    description: "Manage your subscription and billing details",
  },
  "/admin": {
    section: "Admin",
    label: "Admin Console",
    description: "Platform-wide administration and tenant management",
  },
  "/admin/tenants": {
    section: "Admin",
    parent: { label: "Admin Console", href: "/admin" },
    label: "Tenants",
    description: "View and manage all tenant organizations",
  },
  // Without these two the segment fallback took over: the heading read
  // "Platform settings" / "Audit" and both inherited /admin's description, so
  // three different console screens described themselves identically.
  "/admin/audit": {
    section: "Admin",
    parent: { label: "Admin Console", href: "/admin" },
    label: "Platform Audit Log",
    description: "Every audited action across all tenants",
  },
  "/admin/platform-settings": {
    section: "Admin",
    parent: { label: "Admin Console", href: "/admin" },
    label: "Platform Settings",
    description: "Bank account and payment instructions shown to every tenant",
  },
  "/admin/plans": {
    section: "Admin",
    parent: { label: "Admin Console", href: "/admin" },
    label: "Plans",
    description: "Prices, limits and copy the public pricing page reads",
  },

  // ── Profile & Notifications (personal, outside the section tree) ──
  "/profile": {
    label: "My Profile",
    description: "View and update your personal information",
  },
  "/profile/personal": {
    parent: { label: "My Profile", href: "/profile" },
    label: "Personal details",
    description: "Contact details and emergency contacts",
  },
  "/profile/requests": {
    parent: { label: "My Profile", href: "/profile" },
    label: "Change requests",
    description: "Changes to name, birth date, TIN and bank details",
  },
  "/profile/security": {
    parent: { label: "My Profile", href: "/profile" },
    label: "Security",
    description: "Password, two-factor authentication, and active sessions",
  },
  "/profile/preferences": {
    parent: { label: "My Profile", href: "/profile" },
    label: "Preferences",
    description: "Language, theme, and calendar display",
  },
  "/notifications": {
    label: "Notifications",
    description: "Your recent notifications and alerts",
  },
  "/notifications/preferences": {
    parent: { label: "Notifications", href: "/notifications" },
    label: "Preferences",
    description: "Choose which notifications you receive and how",
  },
};

export function routeI18nKey(path: string, field: "label" | "desc"): string {
  const slug =
    path.replace(/^\//, "").replace(/-/g, "_").replace(/\//g, ".") ||
    "dashboard";
  return `route.${slug}.${field}`;
}

export function sectionI18nKey(section: string): string {
  const slug = section
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "_")
    .replace(/^_|_$/g, "");
  return `route.section.${slug}`;
}

/**
 * A path segment that is an opaque identifier rather than a readable route name
 * — a ULID (26 chars, Crockford base32), a UUID, or a bare number.
 *
 * These must never become a page heading. The last segment was previously used
 * as the title unconditionally, so every detail route in the app
 * (`/employees/{ulid}`, `/admin/tenants/{ulid}`, …) rendered a 26-character
 * identifier as its `<h1>` and final breadcrumb.
 */
function isOpaqueId(segment: string): boolean {
  return (
    /^[0-9A-HJKMNP-TV-Z]{26}$/i.test(segment) ||
    /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(
      segment,
    ) ||
    /^\d+$/.test(segment)
  );
}

export function getRouteMeta(pathname: string): RouteMeta | null {
  if (ROUTE_META[pathname]) return ROUTE_META[pathname];

  const segments = pathname.split("/").filter(Boolean);
  if (segments.length >= 2) {
    const parentPath = "/" + segments.slice(0, -1).join("/");
    if (ROUTE_META[parentPath]) {
      const last = segments[segments.length - 1];

      // The record's own name lives in the page body, which is where a reader
      // looks for it; the bar keeps the section it belongs to.
      if (isOpaqueId(last)) return ROUTE_META[parentPath];

      const fallbackLabel = last.replace(/-/g, " ");
      return {
        ...ROUTE_META[parentPath],
        parent: { label: ROUTE_META[parentPath].label, href: parentPath },
        label: fallbackLabel.charAt(0).toUpperCase() + fallbackLabel.slice(1),
      };
    }
  }

  const last = segments[segments.length - 1];

  // Same rule with no known parent to fall back to: a bare identifier is not a
  // page name, so say nothing rather than print the id.
  if (!last || isOpaqueId(last)) return null;

  const fallback = last.replace(/-/g, " ");
  return {
    label: fallback.charAt(0).toUpperCase() + fallback.slice(1),
  };
}
