import {
  Activity,
  AlarmClock,
  Archive,
  Banknote,
  BookOpen,
  Coins,
  Lock,
  Mail,
  Palette,
  ShieldCheck,
  SlidersHorizontal,
  Timer,
  UserCog,
  BarChart3,
  Bell,
  Briefcase,
  Building2,
  Calendar,
  CalendarClock,
  CalendarDays,
  CalendarRange,
  Camera,
  CheckSquare,
  ClipboardCheck,
  Clock,
  Contact,
  CreditCard,
  FilePenLine,
  FileText,
  Fingerprint,
  GitBranch,
  GitMerge,
  GraduationCap,
  KeyRound,
  Landmark,
  Layers,
  Megaphone,
  LayoutDashboard,
  LifeBuoy,
  ListChecks,
  LogIn,
  MessageCircleHeart,
  DatabaseBackup,
  MessageSquareText,
  Play,
  Plus,
  Receipt,
  ScrollText,
  Settings,
  Shield,
  ShieldAlert,
  ToggleRight,
  TrendingUp,
  Upload,
  UserCircle,
  UserPlus,
  UserX,
  Users,
  UsersRound,
  Wallet,
  Webhook,
  type LucideIcon,
  Route,
  Smartphone,
} from "lucide-react";
import type { CanFlag } from "@/lib/hooks/usePermissions";
import { DEFAULT_TIMEZONE, resolveTimeZone, todayIn } from "@/lib/utils/date";

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

/** The label a row is filed under in the palette: a group's, or "Settings". */
export type DestinationContext = Pick<
  DestinationGroup,
  "labelKey" | "labelFallback"
>;

export interface Destination {
  /** Stable, never shown: keys `intent.<id>.phrases` and test names. */
  id: string;
  href: string | ((ctx: NavContext) => string);
  labelKey: string;
  labelFallback: string;
  icon: LucideIcon;
  show: (ctx: NavContext) => boolean;
  /**
   * The sidebar draws a live count next to this row: decisions waiting for
   * the person, or punches waiting for an integrity review.
   */
  badge?: "approvals" | "integrity";
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
    id: "platform_operations",
    href: "/admin/operations",
    labelKey: "nav.platform_operations",
    labelFallback: "Operations",
    icon: Activity,
    show: (c) => c.can.viewAdminConsole,
  },
  {
    id: "platform_support",
    href: "/admin/support",
    labelKey: "nav.platform_support",
    labelFallback: "Support",
    icon: LifeBuoy,
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
    id: "backup_storage",
    href: "/admin/backups",
    labelKey: "nav.backup_storage",
    labelFallback: "Backups",
    icon: DatabaseBackup,
    show: (c) => c.can.viewAdminConsole,
  },
  {
    id: "feature_controls",
    href: "/admin/features",
    labelKey: "nav.feature_controls",
    labelFallback: "Features",
    icon: ToggleRight,
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
  {
    id: "marketing_content",
    href: "/admin/marketing",
    labelKey: "nav.marketing_content",
    labelFallback: "Marketing site",
    icon: Megaphone,
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
// Sections are named for what a person is doing, not for the data behind it
// (docs/CLAUDE.md, Sidebar Navigation Structure):
//
//   Workspace      my own day: dashboard, approvals, attendance, leave, pay
//   People         the workforce: records, structure, leavers, hiring, directory
//   Operations     running attendance: records, shifts, devices
//   Finance        payroll and what it deducts
//   Insights       reports and analytics, read across every area
//   Communication  announcements and Voice, organisation to employee and back
//   Admin          settings, billing, the platform console
//
// Attendance appears twice on purpose, and never to the same person: the
// Workspace row is one's own record, the Operations group is everyone else's.

const TENANT_SECTIONS: NavSectionDef[] = [
  {
    titleKey: "nav.section.workspace",
    titleFallback: "Workspace",
    entries: [
      leaf({
        id: "dashboard",
        href: "/dashboard",
        labelKey: "nav.dashboard",
        labelFallback: "Dashboard",
        icon: LayoutDashboard,
        show: always,
      }),
      // Decisions waiting on the person come first in their own band, with
      // the live count (only rendered for approvers).
      leaf({
        id: "approvals",
        href: "/approvals",
        labelKey: "nav.approvals",
        labelFallback: "Approvals",
        icon: CheckSquare,
        show: (c) => c.isSupervisor,
        badge: "approvals",
      }),
      // Strictly the person's own record. Holders of attendance.viewAll
      // administer everyone's from Operations > Attendance > Records instead.
      leaf({
        id: "my_attendance",
        href: "/attendance",
        labelKey: "nav.my_attendance",
        labelFallback: "My Attendance",
        icon: Clock,
        show: (c) => !c.can.viewAllAttendance,
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
        labelFallback: "Organisation",
        icon: Building2,
        show: (c) => c.can.manageOrg,
      }),
      leaf({
        id: "exit_clearances",
        href: "/employees/exit-clearances",
        labelKey: "nav.exit_clearances",
        labelFallback: "Exit clearances",
        icon: ClipboardCheck,
        show: (c) => c.can.transitionEmployees || c.can.manageLoans,
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
            // in Workspace: see the note on the My Attendance leaf.
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
            id: "overtime_requests",
            href: "/attendance/overtime-requests",
            labelKey: "nav.overtime_requests",
            labelFallback: "Overtime requests",
            icon: Clock,
            show: (c) => c.can.requestOvertime,
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
            badge: "integrity",
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
            // The phones people punch with: approvals and revocations.
            id: "attendance_devices",
            href: "/attendance/devices",
            labelKey: "devices.title",
            labelFallback: "Phones",
            icon: Smartphone,
            show: (c) => c.can.manageAttendance,
          },
          {
            // Stops field staff logged during the day; never a punch.
            id: "attendance_visits",
            href: "/attendance/visits",
            labelKey: "visits.title",
            labelFallback: "Visits",
            icon: Route,
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
      // One expandable Payroll group, so Finance reads as one area rather
      // than three loose rows, the way Attendance does under Operations.
      {
        kind: "group",
        id: "fin-payroll",
        labelKey: "nav.payroll",
        labelFallback: "Payroll",
        icon: Wallet,
        show: (c) => c.can.viewPayrollRuns,
        children: [
          {
            id: "payroll_runs",
            href: "/payroll",
            labelKey: "nav.payroll_runs_short",
            labelFallback: "Runs",
            icon: ListChecks,
            show: (c) => c.can.viewPayrollRuns,
          },
          {
            id: "loans",
            href: "/payroll/loans",
            labelKey: "nav.loans",
            labelFallback: "Loans",
            icon: Banknote,
            show: (c) => c.can.viewPayrollRuns,
          },
          {
            id: "cost_sharing",
            href: "/payroll/cost-sharing",
            labelKey: "nav.cost_sharing",
            labelFallback: "Cost Sharing",
            icon: GraduationCap,
            show: (c) => c.can.viewPayrollRuns,
          },
        ],
      },
    ],
  },
  {
    titleKey: "nav.section.insights",
    titleFallback: "Insights",
    entries: [
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
    titleKey: "nav.section.communication",
    titleFallback: "Communication",
    entries: [
      leaf({
        id: "announcements",
        href: "/announcements",
        labelKey: "nav.announcements",
        labelFallback: "Announcements",
        icon: Megaphone,
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
 * person's own profile, notifications and help requests. The settings pages
 * are `SETTINGS_DESTINATIONS`, below.
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
    // Opened from the header's Get help and the account menu.
    id: "support",
    href: "/support",
    labelKey: "nav.support",
    labelFallback: "Help requests",
    icon: LifeBuoy,
    show: (c) => !c.isSuperAdmin,
  },
  {
    // Reached from the Employees toolbar, the dashboard's Next steps and
    // here; a sidebar row of its own would crowd People for a weekly check.
    id: "contracts_ending",
    href: "/employees/contracts",
    labelKey: "nav.contracts_ending",
    labelFallback: "Contracts ending",
    icon: CalendarClock,
    show: (c) => c.can.manageEmployees,
  },
];
// ── Settings ─────────────────────────────────────────────────────────────────

/**
 * What a settings row's gate reads. A subset of `NavContext`, so the settings
 * navigation, which has no sidebar context of its own, can ask the same
 * question the palette asks.
 */
export type SettingsAccess = Pick<NavContext, "can" | "isSuperAdmin">;

export interface SettingsDestination extends Destination {
  href: string;
  show: (ctx: SettingsAccess) => boolean;
  /**
   * Where the settings navigation links, when that is not the address the
   * palette opens: the left nav's General is `/settings`, which highlights by
   * path, while the palette opens `/settings?tab=general`.
   */
  navHref?: string;
}

export interface SettingsGroup {
  titleKey: string;
  titleFallback: string;
  items: SettingsDestination[];
}

/**
 * The settings hub, once. `settings-nav.tsx` draws it as its left navigation
 * and the command palette searches it, so a page cannot be in one and not the
 * other. Each row carries the ability its page and API check; the people who
 * hold it (an HR admin holds Users, Leave Types and Holidays, not Settings)
 * are the people it is offered to, in both places (audit N91).
 *
 * A row's search phrases are `intent.<id>.phrases`, so "sso", "tax" or
 * "webhooks" find it in every language the dictionaries cover.
 */
export const SETTINGS_GROUPS: SettingsGroup[] = [
  {
    titleKey: "settings.nav.workspace",
    titleFallback: "Workspace",
    items: [
      {
        id: "settings_general",
        href: "/settings?tab=general",
        navHref: "/settings",
        labelKey: "settings.tab_general",
        labelFallback: "General",
        icon: SlidersHorizontal,
        show: (c) => c.can.manageSettings,
      },
    ],
  },
  {
    titleKey: "settings.nav.configuration",
    titleFallback: "Configuration",
    items: [
      {
        id: "attendance_rules",
        href: "/settings/attendance",
        labelKey: "nav.attendance_rules",
        labelFallback: "Attendance Rules",
        icon: Clock,
        show: (c) => c.can.manageAttendance,
      },
      {
        id: "shift_rules",
        href: "/shifts",
        labelKey: "nav.shift_config",
        labelFallback: "Shift Rules",
        icon: Timer,
        show: (c) => c.can.manageShifts,
      },
      {
        id: "leave_types",
        href: "/settings/leave-types",
        labelKey: "nav.leave_types",
        labelFallback: "Leave Types",
        icon: ListChecks,
        show: (c) => c.can.manageLeaveTypes,
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
        id: "exit_clearance_checklist",
        href: "/settings/exit-clearance",
        labelKey: "nav.exit_clearance_checklist",
        labelFallback: "Exit checklist",
        icon: ClipboardCheck,
        show: (c) => c.can.manageSettings,
      },
      {
        id: "payroll_rules",
        href: "/settings/payroll",
        labelKey: "nav.payroll_config",
        labelFallback: "Payroll Rules",
        icon: Coins,
        // payroll.viewConfig reads the rules; changing them takes
        // payroll.manageConfig, which the page checks.
        show: (c) => c.can.viewPayrollConfig && !c.isSuperAdmin,
      },
      {
        id: "opening_balances",
        href: "/settings/opening-balances",
        labelKey: "nav.opening_balances",
        labelFallback: "Opening balances",
        icon: Upload,
        show: (c) => c.can.adjustLeaveBalance || c.can.manageLoans,
      },
    ],
  },
  {
    titleKey: "settings.nav.access",
    titleFallback: "Access & Security",
    items: [
      {
        id: "users",
        href: "/settings/users",
        labelKey: "nav.users",
        labelFallback: "Users & Access",
        icon: UserCog,
        // users.viewAny, which HR admins hold too; the API guards which
        // accounts each may change (N68).
        show: (c) => c.can.viewUsers,
      },
      {
        id: "roles",
        href: "/settings/roles",
        labelKey: "nav.roles",
        labelFallback: "Roles & Permissions",
        icon: ShieldCheck,
        show: (c) => c.can.manageSettings,
      },
    ],
  },
  {
    titleKey: "nav.integrations",
    titleFallback: "Integrations",
    items: [
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
        id: "accounting",
        href: "/settings/accounting",
        labelKey: "nav.accounting",
        labelFallback: "Accounting",
        icon: BookOpen,
        show: (c) => c.can.viewPayrollRuns,
      },
      {
        id: "notification_templates",
        href: "/settings/notification-templates",
        labelKey: "nav.notification_templates",
        labelFallback: "Notification Templates",
        icon: Mail,
        show: (c) => c.can.manageSettings,
      },
      {
        id: "scim",
        href: "/settings/scim",
        labelKey: "nav.scim",
        labelFallback: "SCIM Provisioning",
        icon: UserCog,
        show: (c) => c.can.manageSettings,
      },
    ],
  },
  {
    titleKey: "settings.nav.compliance",
    titleFallback: "Compliance",
    items: [
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
        icon: Archive,
        show: (c) => c.can.manageSettings,
      },
    ],
  },
];

/**
 * The tabs of Settings > General beyond the first, which is the page itself
 * (`settings_general` above). Palette only: the settings navigation lists
 * pages, and a tab is reached from the page. `?tab=` is what
 * `settings/page.tsx` reads, and `settings-search.test.ts` keeps the two
 * lists equal. Each tab is on the page's own gate, `settings.manage`.
 */
export const SETTINGS_TABS: SettingsDestination[] = [
  {
    id: "settings_branding",
    href: "/settings?tab=branding",
    labelKey: "settings.tab_branding",
    labelFallback: "Branding",
    icon: Palette,
    show: (c) => c.can.manageSettings,
  },
  {
    id: "settings_security",
    href: "/settings?tab=security",
    labelKey: "settings.tab_security",
    labelFallback: "Security",
    icon: Lock,
    show: (c) => c.can.manageSettings,
  },
  {
    id: "settings_sso",
    href: "/settings?tab=sso",
    labelKey: "settings.tab_sso",
    labelFallback: "SSO",
    icon: Fingerprint,
    show: (c) => c.can.manageSettings,
  },
];

/** Every settings destination the palette can offer: General, its tabs, then the pages. */
export const SETTINGS_DESTINATIONS: SettingsDestination[] = [
  ...SETTINGS_GROUPS[0].items,
  ...SETTINGS_TABS,
  ...SETTINGS_GROUPS.slice(1).flatMap((g) => g.items),
];

/** What a settings row is filed under in the palette: "Settings > Holidays". */
export const SETTINGS_CONTEXT: DestinationContext = {
  labelKey: "nav.settings",
  labelFallback: "Settings",
};

/**
 * The first page of the hub this person may open, for the sidebar's Settings
 * row, or null when none is theirs. A page inside the hub first: only
 * /settings/* renders the settings navigation, and Shift Rules leads to
 * /shifts, outside it, so landing there would leave an HR admin with no way on
 * to Leave Types, Holidays or Users (audit N98).
 */
export function settingsLandingFor(access: SettingsAccess): string | null {
  const hrefs = SETTINGS_GROUPS.flatMap((g) => g.items)
    .filter((i) => i.show(access))
    .map((i) => i.navHref ?? i.href);
  return hrefs.find((h) => h.startsWith("/settings")) ?? hrefs[0] ?? null;
}

// ── Quick actions ────────────────────────────────────────────────────────────

/**
 * Today's attendance for the people this person may see, filtered to one
 * status. The team page has no status filter, so a supervisor gets the day's
 * team view, which shows each person's status.
 */
function todayWithStatusHref(status: "absent" | "late") {
  return (ctx: NavContext): string => {
    if (ctx.can.viewAllAttendance) {
      return `/attendance?status=${status}&from=${ctx.today}&to=${ctx.today}`;
    }
    return "/attendance/team";
  };
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
    href: todayWithStatusHref("absent"),
    labelKey: "intent.absent_today.label",
    labelFallback: "Who is absent today",
    descKey: "intent.absent_today.desc",
    descFallback:
      "Opens today's attendance, filtered to the people marked absent.",
    icon: UserX,
    show: (c) => c.can.viewAllAttendance || c.can.viewTeam,
  },
  {
    id: "late_today",
    href: todayWithStatusHref("late"),
    labelKey: "intent.late_today.label",
    labelFallback: "Who is late today",
    descKey: "intent.late_today.desc",
    descFallback:
      "Opens today's attendance, filtered to the people marked late.",
    icon: AlarmClock,
    show: (c) => c.can.viewAllAttendance || c.can.viewTeam,
  },
  {
    id: "check_in",
    // Someone who sees everyone's attendance gets the records list at
    // /attendance, not a check-in button; their own punch is the punch page,
    // where My Day's attendance tile already sends them.
    href: (c) =>
      c.can.viewAllAttendance ? "/attendance/mobile" : "/attendance",
    labelKey: "intent.check_in.label",
    labelFallback: "Check in or out",
    descKey: "intent.check_in.desc",
    descFallback: "Opens the page where you check in and out.",
    icon: LogIn,
    show: (c) => !c.isSuperAdmin,
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
    id: "update_my_details",
    href: "/profile/personal",
    labelKey: "intent.update_my_details.label",
    labelFallback: "Update my phone or emergency contacts",
    descKey: "intent.update_my_details.desc",
    descFallback:
      "Opens your personal details, where you change these yourself.",
    icon: UserCircle,
    show: (c) => !c.isSuperAdmin,
  },
  {
    id: "request_profile_change",
    href: "/profile/requests",
    labelKey: "intent.request_profile_change.label",
    labelFallback: "Ask to change my name, TIN or bank details",
    descKey: "intent.request_profile_change.desc",
    descFallback:
      "Opens your change requests. HR reviews each one before it applies.",
    icon: FilePenLine,
    show: (c) => !c.isSuperAdmin,
  },
  {
    id: "my_security",
    href: "/profile/security",
    labelKey: "intent.my_security.label",
    labelFallback: "Change my password or two-factor",
    descKey: "intent.my_security.desc",
    descFallback:
      "Opens your sign-in security: password, two-factor and active sessions.",
    icon: KeyRound,
    show: (c) => !c.isSuperAdmin,
  },
  {
    id: "import_employees",
    href: "/employees/import",
    labelKey: "intent.import_employees.label",
    labelFallback: "Import employees from a file",
    descKey: "intent.import_employees.desc",
    descFallback:
      "Opens the import. Rows are checked and shown to you before any is saved.",
    icon: Upload,
    show: (c) => c.can.manageEmployees,
  },
  {
    id: "find_duplicates",
    href: "/employees/duplicates",
    labelKey: "intent.find_duplicates.label",
    labelFallback: "Find employees recorded twice",
    descKey: "intent.find_duplicates.desc",
    descFallback:
      "Opens the list of current employees who share a national ID, email, phone or badge number. Nothing is changed there.",
    icon: UsersRound,
    show: (c) => c.can.manageEmployees,
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

/**
 * Today as an ISO date where the organisation is. `toISOString()` gave the
 * UTC date, which is yesterday in Addis Ababa until 03:00, so a destination
 * that opens "today" opened the day before. Callers that know the tenant's
 * zone pass it; the default is the platform's.
 */
export function isoToday(timeZone: string = DEFAULT_TIMEZONE): string {
  return todayIn(resolveTimeZone(timeZone));
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
): Array<Destination & { context?: DestinationContext }> {
  const out: Array<Destination & { context?: DestinationContext }> = [];
  const seen = new Set<string>();
  const add = (d: Destination, context?: DestinationContext) => {
    if (!d.show(ctx)) return;
    const href = resolveHref(d.href, ctx);
    if (seen.has(href)) return;
    // The sidebar's Settings row leads to the first settings page the person
    // holds, so for an HR admin it is /settings/holidays. It answers to
    // "settings" alone; letting it claim that address hid Holidays from a
    // search for "holidays".
    if (d.id !== "settings") seen.add(href);
    out.push(context ? { ...d, context } : d);
  };
  for (const section of navSections(ctx)) {
    for (const entry of section.entries) {
      if (entry.kind === "leaf") add(entry);
      else if (entry.show(ctx)) entry.children.forEach((c) => add(c, entry));
    }
  }
  MORE_DESTINATIONS.forEach((d) => add(d));
  // Tenant configuration: a platform account has no organisation to configure.
  if (!ctx.isSuperAdmin) {
    SETTINGS_DESTINATIONS.forEach((d) => add(d, SETTINGS_CONTEXT));
  }
  return out;
}

/**
 * The sidebar section that holds this page for this person, or null when the
 * sidebar has no row for it.
 *
 * The breadcrumb names its section from here, so it says what the sidebar
 * says (production QA 2026-10-28, F-03). It kept its own list in
 * `route-meta.ts`, which had drifted: Reports under Finance, Announcements
 * under "My Work" while the sidebar said Insights and Communication. And one
 * address can sit in two places: `/attendance` is Workspace for someone who
 * sees only their own record and Operations for someone who sees everyone's.
 */
export function sidebarSectionOf(
  path: string,
  ctx: NavContext,
): NavSectionDef | null {
  const target = path.length > 1 ? path.replace(/\/+$/, "") : path;
  const holds = (d: Destination) =>
    d.show(ctx) && resolveHref(d.href, ctx).split("?")[0] === target;
  for (const section of navSections(ctx)) {
    for (const entry of section.entries) {
      if (
        entry.kind === "leaf"
          ? holds(entry)
          : entry.show(ctx) && entry.children.some(holds)
      ) {
        return section;
      }
    }
  }
  return null;
}
