import {
  Banknote,
  BarChart3,
  Bell,
  Briefcase,
  Building2,
  Calendar,
  CalendarDays,
  CalendarRange,
  Camera,
  CheckSquare,
  Clock,
  Contact,
  CreditCard,
  Download,
  FilePenLine,
  FileText,
  Fingerprint,
  GitBranch,
  GitMerge,
  GraduationCap,
  KeyRound,
  Landmark,
  Layers,
  LayoutDashboard,
  ListChecks,
  LogIn,
  Megaphone,
  MessageCircleHeart,
  MessageSquareText,
  Play,
  Plus,
  Receipt,
  ScrollText,
  Settings,
  Shield,
  ShieldAlert,
  TrendingUp,
  UserCircle,
  UserPlus,
  UserX,
  Users,
  UsersRound,
  Wallet,
  Webhook,
  type LucideIcon,
} from "lucide-react";
import type { CanFlag } from "@/lib/hooks/usePermissions";

/**
 * The one list of where the app can take a person.
 *
 * The sidebar and the command palette each kept their own copy of it, and
 * the two had drifted: the palette offered nothing under Attendance but the
 * list itself, and the sidebar knew nothing the palette could search by. Both
 * now read this file. Everything here is data: no hooks, so a test can walk
 * it with any permission set and say what each role sees.
 *
 * `show` carries the ability the destination's own API checks, as the
 * sidebar always has (audit N78), so the palette can never offer a page the
 * sidebar would hide. Hiding is not authorization: the backend decides.
 */
export interface NavContext {
  can: Record<CanFlag, boolean>;
  isSupervisor: boolean;
  isTenantAdmin: boolean;
  isSuperAdmin: boolean;
  /** Where the Settings entry leads for this person, or null for no settings page. */
  settingsLanding: string | null;
  /** Today's date, ISO, for destinations that open one day. */
  today: string;
}

export interface Destination {
  /** Stable, never shown: keys `intent.<id>.phrases` and test names. */
  id: string;
  href: string | ((ctx: NavContext) => string);
  labelKey: string;
  labelFallback: string;
  icon: LucideIcon;
  show: (ctx: NavContext) => boolean;
  /** The sidebar draws a live count next to this row. */
  badge?: "approvals";
}

export interface DestinationGroup {
  kind: "group";
  /** Stable key for expand/collapse state. Not a route. */
  id: string;
  labelKey: string;
  labelFallback: string;
  icon: LucideIcon;
  show: (ctx: NavContext) => boolean;
  children: Destination[];
}

export type NavEntryDef = (Destination & { kind: "leaf" }) | DestinationGroup;

export interface NavSectionDef {
  titleKey?: string;
  titleFallback?: string;
  entries: NavEntryDef[];
}

/**
 * A task someone can name, with its own row in the palette: a label, a
 * sentence on what opening it does, and the phrases people use for it.
 * Opening one navigates and nothing more. "Run payroll" lands on the runs
 * list; a run is created, calculated and approved only by the buttons there.
 */
export interface QuickAction {
  id: string;
  href: string | ((ctx: NavContext) => string);
  labelKey: string;
  labelFallback: string;
  descKey: string;
  descFallback: string;
  icon: LucideIcon;
  show: (ctx: NavContext) => boolean;
}

const leaf = (d: Destination): NavEntryDef => ({ ...d, kind: "leaf" });
const always = () => true;

// ── Platform (super admin) ───────────────────────────────────────────────────
//
// A platform super admin belongs to no tenant, so every tenant destination
// ("My Attendance", "Employees", "Payroll Runs", …) is either empty or an
// error for them, and on the platform hostname the middleware bounces each
// one straight back to /admin. The console is the whole navigation for this
// persona.

const PLATFORM_CHILDREN: Destination[] = [
  {
    id: "admin",
    href: "/admin",
    labelKey: "nav.admin",
    labelFallback: "Admin Console",
    icon: Shield,
    show: (c) => c.can.viewAdminConsole,
  },
  {
    id: "tenants",
    href: "/admin/tenants",
    labelKey: "nav.tenants",
    labelFallback: "Tenants",
    icon: Building2,
    show: (c) => c.can.viewAdminConsole,
  },
  {
    id: "platform_audit",
    href: "/admin/audit",
    labelKey: "nav.platform_audit",
    labelFallback: "Audit Log",
    icon: ScrollText,
    show: (c) => c.can.viewAdminConsole,
  },
  {
    id: "platform_settings",
    href: "/admin/platform-settings",
    labelKey: "nav.platform_settings",
    labelFallback: "Platform Settings",
    icon: Landmark,
    show: (c) => c.can.viewAdminConsole,
  },
  {
    id: "sms_delivery",
    href: "/admin/sms",
    labelKey: "nav.sms_delivery",
    labelFallback: "SMS delivery",
    icon: MessageSquareText,
    show: (c) => c.can.viewAdminConsole,
  },
  {
    id: "plans",
    href: "/admin/plans",
    labelKey: "nav.plans",
    labelFallback: "Plans",
    icon: Layers,
    show: (c) => c.can.viewAdminConsole,
  },
];

const PLATFORM_SECTIONS: NavSectionDef[] = [
  {
    titleKey: "nav.section.platform",
    titleFallback: "Platform",
    entries: PLATFORM_CHILDREN.map((d) => leaf({ ...d, show: always })),
  },
];

// ── Tenant navigation ────────────────────────────────────────────────────────
//
// Sections follow the structure specified in CLAUDE.md (Overview / People /
// Operations / Finance / Admin), plus a "My Work" band at the top.
//
// The split between "My Work" and "Operations" is by *persona*, not by data
// domain: attendance appears in both, but "My Work > My Attendance" is the
// employee's own record while "Operations > Attendance" holds the
// administration of everyone else's. Grouping by who does the task keeps each
// band scannable for the role that owns it, instead of forcing every employee
// past nine admin screens to reach their own timesheet.

const TENANT_SECTIONS: NavSectionDef[] = [
  {
    entries: [
      leaf({
        id: "dashboard",
        href: "/dashboard",
        labelKey: "nav.dashboard",
        labelFallback: "Dashboard",
        icon: LayoutDashboard,
        show: always,
      }),
    ],
  },
  {
    titleKey: "nav.section.my_work",
    titleFallback: "My Work",
    entries: [
      // Strictly the person's own record. Holders of attendance.viewAll
      // administer everyone's from Operations > Attendance > Records instead:
      // each persona sees exactly one attendance entry.
      leaf({
        id: "my_attendance",
        href: "/attendance",
        labelKey: "nav.my_attendance",
        labelFallback: "My Attendance",
        icon: Clock,
        show: (c) => !c.can.viewAllAttendance,
      }),
      // Approvals carries a live pending-count badge, so it sits directly
      // under the daily check-in: actionable, time-sensitive work belongs near
      // the top of the personal band (only rendered for approvers).
      leaf({
        id: "approvals",
        href: "/approvals",
        labelKey: "nav.approvals",
        labelFallback: "Approvals",
        icon: CheckSquare,
        show: (c) => c.isSupervisor,
        badge: "approvals",
      }),
      leaf({
        id: "leave",
        href: "/leave",
        labelKey: "nav.leave",
        labelFallback: "Leave",
        icon: CalendarDays,
        show: always,
      }),
      leaf({
        id: "payslips",
        href: "/payroll/payslips",
        labelKey: "nav.payslips",
        labelFallback: "My Payslips",
        icon: Receipt,
        show: always,
      }),
      leaf({
        id: "announcements",
        href: "/announcements",
        labelKey: "nav.announcements",
        labelFallback: "Announcements",
        icon: Megaphone,
        show: always,
      }),
      leaf({
        id: "vacancies",
        href: "/vacancies",
        labelKey: "nav.vacancies",
        labelFallback: "Vacancies",
        icon: Briefcase,
        show: always,
      }),
      leaf({
        id: "voice",
        href: "/voice",
        labelKey: "nav.voice",
        labelFallback: "Voice",
        icon: MessageCircleHeart,
        show: always,
      }),
    ],
  },
  {
    titleKey: "nav.section.people",
    titleFallback: "People",
    entries: [
      leaf({
        id: "employees",
        href: "/employees",
        labelKey: "nav.employees",
        labelFallback: "Employees",
        icon: Users,
        show: (c) => c.can.manageEmployees,
      }),
      leaf({
        id: "organization",
        href: "/organization",
        labelKey: "nav.organization",
        labelFallback: "Organization",
        icon: Building2,
        show: (c) => c.can.manageOrg,
      }),
      leaf({
        id: "directory",
        href: "/directory",
        labelKey: "nav.directory",
        labelFallback: "Directory",
        icon: Contact,
        show: always,
      }),
    ],
  },
  // The attendance, shifts and devices pages each render their own
  // exhaustive in-page tab strip. The sidebar therefore carries the area plus
  // only its highest-frequency sub-destinations rather than mirroring every
  // child: two complete, competing navigations for the same level force the
  // user to learn both and disagree on labels. Sidebar = shortcut layer, page
  // tabs = complete set.
  {
    titleKey: "nav.section.operations",
    titleFallback: "Operations",
    entries: [
      {
        kind: "group",
        id: "ops-attendance",
        labelKey: "nav.attendance",
        labelFallback: "Attendance",
        icon: Clock,
        // Each child carries the ability its screen's API checks, so the
        // group opens for a custom role holding any of them rather than for a
        // role level alone (audit N78).
        show: (c) =>
          c.isSupervisor ||
          c.can.viewTeam ||
          c.can.viewAllAttendance ||
          c.can.viewAttendancePhotos,
        children: [
          {
            // The whole organisation's records (/attendance). Lives here, not
            // in My Work: see the note on the My Attendance leaf.
            id: "attendance_records",
            href: "/attendance",
            labelKey: "nav.attendance_records",
            labelFallback: "Records",
            icon: ListChecks,
            show: (c) => c.can.viewAllAttendance,
          },
          {
            id: "team_attendance",
            href: "/attendance/team",
            labelKey: "nav.team_attendance",
            labelFallback: "Team Attendance",
            icon: UsersRound,
            show: (c) => c.can.viewTeam,
          },
          {
            id: "corrections",
            href: "/attendance/corrections",
            labelKey: "nav.corrections",
            labelFallback: "Corrections",
            icon: FilePenLine,
            show: always,
          },
          {
            id: "conflicts",
            href: "/attendance/conflicts",
            labelKey: "nav.conflicts",
            labelFallback: "Conflicts",
            icon: GitMerge,
            show: (c) => c.can.viewAttendanceConflicts,
          },
          {
            id: "integrity",
            href: "/attendance/integrity",
            labelKey: "nav.integrity",
            labelFallback: "Integrity",
            icon: ShieldAlert,
            show: (c) => c.can.viewAttendanceConflicts,
          },
          {
            id: "overtime",
            href: "/attendance/overtime",
            labelKey: "nav.overtime",
            labelFallback: "Overtime",
            icon: TrendingUp,
            show: (c) => c.can.viewAllAttendance,
          },
          {
            // Selfies under the retention policy: HR and department managers
            // review and hold them before deletion.
            id: "attendance_photos",
            href: "/attendance/photos",
            labelKey: "nav.attendance_photos",
            labelFallback: "Photos",
            icon: Camera,
            show: (c) => c.can.viewAttendancePhotos,
          },
        ],
      },
      leaf({
        id: "shifts",
        href: "/shifts",
        labelKey: "nav.shifts",
        labelFallback: "Shifts & Schedules",
        icon: CalendarRange,
        show: (c) => c.can.manageShifts,
      }),
      leaf({
        id: "devices",
        href: "/devices",
        labelKey: "nav.devices",
        labelFallback: "Devices",
        icon: Fingerprint,
        show: (c) => c.can.viewDevices,
      }),
    ],
  },
  {
    titleKey: "nav.section.finance",
    titleFallback: "Finance",
    entries: [
      leaf({
        id: "payroll_runs",
        href: "/payroll",
        labelKey: "nav.payroll_runs",
        labelFallback: "Payroll Runs",
        icon: Wallet,
        show: (c) => c.can.viewPayrollRuns,
      }),
      leaf({
        id: "loans",
        href: "/payroll/loans",
        labelKey: "nav.loans",
        labelFallback: "Loans",
        icon: Banknote,
        show: (c) => c.can.viewPayrollRuns,
      }),
      leaf({
        id: "cost_sharing",
        href: "/payroll/cost-sharing",
        labelKey: "nav.cost_sharing",
        labelFallback: "Cost Sharing",
        icon: GraduationCap,
        show: (c) => c.can.viewPayrollRuns,
      }),
      leaf({
        id: "reports",
        href: "/reports",
        labelKey: "nav.reports",
        labelFallback: "Reports",
        icon: BarChart3,
        show: (c) => c.can.viewReports,
      }),
      leaf({
        id: "analytics",
        href: "/analytics",
        labelKey: "nav.analytics",
        labelFallback: "Analytics",
        icon: TrendingUp,
        // The page is for either dashboard ability; HR, finance and regional
        // users were left out before (audit N85).
        show: (c) =>
          c.can.viewExecutiveDashboard || c.can.viewRegionalDashboard,
      }),
    ],
  },
  {
    titleKey: "nav.section.admin",
    titleFallback: "Admin",
    entries: [
      // Configuration lives behind a single destination with its own
      // sub-navigation (components/layouts/settings-nav.tsx): one sidebar row,
      // every settings route still reachable inside the hub.
      leaf({
        id: "settings",
        href: (c) => c.settingsLanding ?? "/settings",
        labelKey: "nav.settings",
        labelFallback: "Settings",
        icon: Settings,
        show: (c) => c.settingsLanding !== null,
      }),
      leaf({
        id: "billing",
        href: "/billing",
        labelKey: "nav.billing",
        labelFallback: "Billing",
        icon: CreditCard,
        show: (c) => c.isTenantAdmin,
      }),
      {
        kind: "group",
        id: "admin-platform",
        labelKey: "nav.platform",
        labelFallback: "Platform",
        icon: Shield,
        show: (c) => c.can.viewAdminConsole,
        children: PLATFORM_CHILDREN,
      },
    ],
  },
];

/** The sidebar tree for this person. */
export function navSections(ctx: NavContext): NavSectionDef[] {
  return ctx.isSuperAdmin ? PLATFORM_SECTIONS : TENANT_SECTIONS;
}

/**
 * Pages with no sidebar row of their own, reachable from the palette: the
 * person's own profile and notifications, and the settings pages the hub
 * lists. Each settings row repeats the gate `settings-nav.tsx` applies.
 */
export const MORE_DESTINATIONS: Destination[] = [
  {
    id: "profile",
    href: "/profile",
    labelKey: "nav.my_profile",
    labelFallback: "My Profile",
    icon: UserCircle,
    show: (c) => !c.isSuperAdmin,
  },
  {
    id: "notifications",
    href: "/notifications",
    labelKey: "nav.notifications",
    labelFallback: "Notifications",
    icon: Bell,
    show: (c) => !c.isSuperAdmin,
  },
  {
    id: "users",
    href: "/settings/users",
    labelKey: "nav.users",
    labelFallback: "Users & Access",
    icon: Users,
    show: (c) => c.can.viewUsers,
  },
  {
    id: "roles",
    href: "/settings/roles",
    labelKey: "nav.roles",
    labelFallback: "Roles & Permissions",
    icon: Shield,
    show: (c) => c.can.manageSettings,
  },
  {
    id: "holidays",
    href: "/settings/holidays",
    labelKey: "nav.holidays",
    labelFallback: "Holidays",
    icon: Calendar,
    show: (c) => c.can.manageHolidays,
  },
  {
    id: "approval_chains",
    href: "/settings/approval-chains",
    labelKey: "nav.approval_chains",
    labelFallback: "Approval chains",
    icon: GitBranch,
    show: (c) => c.can.manageSettings,
  },
  {
    id: "leave_types",
    href: "/settings/leave-types",
    labelKey: "nav.leave_types",
    labelFallback: "Leave Types",
    icon: CalendarDays,
    show: (c) => c.can.manageLeaveTypes,
  },
  {
    id: "attendance_rules",
    href: "/attendance/settings",
    labelKey: "nav.attendance_rules",
    labelFallback: "Attendance Rules",
    icon: Clock,
    show: (c) => c.can.manageAttendance,
  },
  {
    id: "shift_rules",
    href: "/settings/shifts",
    labelKey: "nav.shift_config",
    labelFallback: "Shift Rules",
    icon: CalendarRange,
    show: (c) => c.can.manageShifts,
  },
  {
    id: "payroll_rules",
    href: "/settings/payroll",
    labelKey: "nav.payroll_config",
    labelFallback: "Payroll Rules",
    icon: Wallet,
    show: (c) => c.isTenantAdmin && !c.isSuperAdmin,
  },
  {
    id: "api_keys",
    href: "/settings/api-keys",
    labelKey: "nav.api_keys",
    labelFallback: "API Keys",
    icon: KeyRound,
    show: (c) => c.can.manageApiKeys,
  },
  {
    id: "webhooks",
    href: "/settings/webhooks",
    labelKey: "nav.webhooks",
    labelFallback: "Webhooks",
    icon: Webhook,
    show: (c) => c.can.manageWebhooks,
  },
  {
    id: "audit_log",
    href: "/settings/audit-logs",
    labelKey: "nav.audit_log",
    labelFallback: "Audit Log",
    icon: ScrollText,
    show: (c) => c.can.manageSettings,
  },
  {
    id: "data_export",
    href: "/settings/data-export",
    labelKey: "nav.data_export",
    labelFallback: "Data export",
    icon: Download,
    show: (c) => c.can.manageSettings,
  },
];

// ── Quick actions ────────────────────────────────────────────────────────────

/** Today's attendance for the people this person may see, filtered to absent. */
function absentTodayHref(ctx: NavContext): string {
  if (ctx.can.viewAllAttendance) {
    return `/attendance?status=absent&from=${ctx.today}&to=${ctx.today}`;
  }
  return "/attendance/team";
}

export const QUICK_ACTIONS: QuickAction[] = [
  {
    id: "add_employee",
    href: "/employees/new",
    labelKey: "command.add_employee",
    labelFallback: "Add Employee",
    descKey: "intent.add_employee.desc",
    descFallback:
      "Opens the new employee form. Nothing is saved until you submit it.",
    icon: Plus,
    show: (c) => c.can.manageEmployees,
  },
  {
    id: "absent_today",
    href: absentTodayHref,
    labelKey: "intent.absent_today.label",
    labelFallback: "Who is absent today",
    descKey: "intent.absent_today.desc",
    descFallback:
      "Opens today's attendance, filtered to the people marked absent.",
    icon: UserX,
    show: (c) => c.can.viewAllAttendance || c.can.viewTeam,
  },
  {
    id: "check_in",
    href: "/attendance",
    labelKey: "intent.check_in.label",
    labelFallback: "Check in or out",
    descKey: "intent.check_in.desc",
    descFallback: "Opens your attendance page, where the check-in button is.",
    icon: LogIn,
    show: (c) => !c.isSuperAdmin && !c.can.viewAllAttendance,
  },
  {
    id: "correct_attendance",
    href: "/attendance/corrections",
    labelKey: "intent.correct_attendance.label",
    labelFallback: "Correct an attendance record",
    descKey: "intent.correct_attendance.desc",
    descFallback:
      "Request a fix for a missed or wrong punch, or review the requests waiting on you.",
    icon: FilePenLine,
    show: (c) => !c.isSuperAdmin,
  },
  {
    id: "pending_approvals",
    href: "/approvals",
    labelKey: "intent.pending_approvals.label",
    labelFallback: "Review pending approvals",
    descKey: "intent.pending_approvals.desc",
    descFallback:
      "Leave requests, corrections and profile changes waiting for your decision.",
    icon: CheckSquare,
    show: (c) => c.isSupervisor && !c.isSuperAdmin,
  },
  {
    id: "team_leave",
    href: "/leave?tab=team",
    labelKey: "intent.team_leave.label",
    labelFallback: "Who is on leave",
    descKey: "intent.team_leave.desc",
    descFallback: "Your team's leave requests, and who is away.",
    icon: CalendarDays,
    show: (c) => c.isSupervisor && !c.isSuperAdmin,
  },
  {
    id: "request_leave",
    // The leave page holds the request form; /leave/new never existed.
    href: "/leave",
    labelKey: "command.request_leave",
    labelFallback: "Request Leave",
    descKey: "intent.request_leave.desc",
    descFallback:
      "Opens your leave page, with the request form and your balances.",
    icon: CalendarDays,
    show: (c) => !c.isSuperAdmin,
  },
  {
    id: "run_payroll",
    href: "/payroll",
    labelKey: "command.run_payroll",
    labelFallback: "Run Payroll",
    descKey: "intent.run_payroll.desc",
    descFallback:
      "Opens payroll runs. A run is calculated only when you start one there, and nothing is paid until it is approved.",
    icon: Play,
    show: (c) => c.can.processPayroll,
  },
  {
    id: "generate_report",
    href: "/reports",
    labelKey: "command.generate_report",
    labelFallback: "Generate Report",
    descKey: "intent.generate_report.desc",
    descFallback:
      "Opens the report builder: attendance, leave, payroll and headcount.",
    icon: FileText,
    show: (c) => c.can.viewReports,
  },
  {
    id: "invite_user",
    href: "/settings/users",
    labelKey: "intent.invite_user.label",
    labelFallback: "Invite someone to sign in",
    descKey: "intent.invite_user.desc",
    descFallback: "Users and access: invite a person and choose their role.",
    icon: UserPlus,
    show: (c) => c.can.viewUsers,
  },
];

// ── Helpers ──────────────────────────────────────────────────────────────────

/** Today as an ISO date, the way the team attendance page already reads it. */
export function isoToday(): string {
  return new Date().toISOString().slice(0, 10);
}

export function resolveHref(
  href: string | ((ctx: NavContext) => string),
  ctx: NavContext,
): string {
  return typeof href === "function" ? href(ctx) : href;
}

/** The translation key of a row's search phrases, `|`-separated in the dictionary. */
export function phrasesKey(id: string): string {
  return `intent.${id}.phrases`;
}

/**
 * Every destination this person may open, one row per address, in sidebar
 * order with the palette-only pages last. A child carries its group's label
 * as `context`, so "Records" reads as "Attendance Records" on its own.
 */
export function visibleDestinations(
  ctx: NavContext,
): Array<Destination & { context?: DestinationGroup }> {
  const out: Array<Destination & { context?: DestinationGroup }> = [];
  const seen = new Set<string>();
  const add = (d: Destination, context?: DestinationGroup) => {
    if (!d.show(ctx)) return;
    const href = resolveHref(d.href, ctx);
    if (seen.has(href)) return;
    seen.add(href);
    out.push(context ? { ...d, context } : d);
  };
  for (const section of navSections(ctx)) {
    for (const entry of section.entries) {
      if (entry.kind === "leaf") add(entry);
      else if (entry.show(ctx)) entry.children.forEach((c) => add(c, entry));
    }
  }
  MORE_DESTINATIONS.forEach((d) => add(d));
  return out;
}
