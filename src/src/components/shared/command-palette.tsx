"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import {
  Banknote,
  BarChart3,
  Briefcase,
  Building2,
  Calendar,
  CalendarDays,
  CheckSquare,
  Clock,
  Contact,
  CreditCard,
  FilePenLine,
  FileText,
  Fingerprint,
  GraduationCap,
  KeyRound,
  Landmark,
  Layers,
  LayoutDashboard,
  Loader2,
  Megaphone,
  MessageCircleHeart,
  MessageSquareText,
  Play,
  Plus,
  Receipt,
  ScrollText,
  Settings,
  Shield,
  TrendingUp,
  type LucideIcon,
  UserCircle,
  Users,
  Wallet,
  Webhook,
} from "lucide-react";
import {
  CommandDialog,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
  CommandSeparator,
} from "@/components/ui/command";
import { StatusBadge } from "@/components/shared/status-badge";
import {
  type RecentEntry,
  RECENT_KIND_ICON as KIND_ICON,
  useRecentSearches,
} from "@/components/shared/recent-searches";
import { useCurrentUser } from "@/features/auth/api";
import { MIN_QUERY_LENGTH, useGlobalSearch } from "@/features/search/api";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useDebounce } from "@/lib/hooks/useDebounce";
import { useT } from "@/lib/i18n/useT";
import { useSettingsLanding } from "@/components/layouts/settings-nav";

/** Event any UI can fire to open the palette — cleaner than faking a ⌘K keypress. */
const OPEN_EVENT = "command-palette:open";

/**
 * Open the command palette from anywhere (header search box, mobile trigger).
 * Decoupled via a window event so callers don't need the palette's state.
 */
export function openCommandPalette(): void {
  if (typeof window !== "undefined") {
    window.dispatchEvent(new CustomEvent(OPEN_EVENT));
  }
}

/** True while the user is typing into a field, where "/" is just a character. */
function isTypingTarget(target: EventTarget | null): boolean {
  if (!(target instanceof HTMLElement)) return false;
  if (target.isContentEditable) return true;
  const tag = target.tagName;
  return tag === "INPUT" || tag === "TEXTAREA" || tag === "SELECT";
}

interface PaletteItem {
  label: string;
  href?: string;
  icon: LucideIcon;
  keywords?: string;
  show: boolean;
}

interface PaletteGroup {
  heading: string;
  items: PaletteItem[];
}

export function CommandPalette() {
  const [open, setOpen] = useState(false);
  const [search, setSearch] = useState("");
  const router = useRouter();
  const { can, isSupervisor, isTenantAdmin } = usePermissions();
  const settingsLanding = useSettingsLanding();
  const { t } = useT();
  const { data: user } = useCurrentUser();
  const { formatDate } = useDateFormatters();

  // Open/close through one handler that also resets the query, so the palette
  // always opens fresh — done at the event, never via a setState-in-effect.
  const setPaletteOpen = useCallback((next: boolean) => {
    setOpen(next);
    setSearch("");
  }, []);

  useEffect(() => {
    function onKeyDown(e: KeyboardEvent) {
      if (e.key === "k" && (e.metaKey || e.ctrlKey)) {
        e.preventDefault();
        setSearch("");
        setOpen((prev) => !prev);
        return;
      }
      // "/" opens search, as on most web apps, except while typing somewhere.
      if (
        e.key === "/" &&
        !e.metaKey &&
        !e.ctrlKey &&
        !e.altKey &&
        !isTypingTarget(e.target)
      ) {
        e.preventDefault();
        setSearch("");
        setOpen(true);
      }
    }
    document.addEventListener("keydown", onKeyDown);
    return () => document.removeEventListener("keydown", onKeyDown);
  }, []);

  // Open (never toggle) when a header/mobile trigger asks for it.
  useEffect(() => {
    function onOpen() {
      setPaletteOpen(true);
    }
    window.addEventListener(OPEN_EVENT, onOpen);
    return () => window.removeEventListener(OPEN_EVENT, onOpen);
  }, [setPaletteOpen]);

  const navigate = useCallback(
    (href: string) => {
      setPaletteOpen(false);
      router.push(href);
    },
    [router, setPaletteOpen],
  );

  const groups: PaletteGroup[] = useMemo(
    () => [
      {
        heading: t("command.nav", "Navigation"),
        items: [
          {
            label: t("nav.dashboard", "Dashboard"),
            href: "/dashboard",
            icon: LayoutDashboard,
            show: true,
          },
          {
            label: t("nav.my_profile", "My Profile"),
            href: "/profile",
            icon: UserCircle,
            show: true,
          },
          {
            label: t("nav.directory", "Directory"),
            href: "/directory",
            icon: Contact,
            keywords: "search people find",
            show: true,
          },
          {
            label: t("nav.employees", "Employees"),
            href: "/employees",
            icon: Users,
            show: can.manageEmployees,
          },
          {
            label: t("nav.organization", "Organization"),
            href: "/organization",
            icon: Building2,
            keywords: "departments branches positions grades teams",
            show: can.manageOrg,
          },
          {
            label: t("nav.attendance", "Attendance"),
            href: "/attendance",
            icon: Clock,
            show: true,
          },
          {
            label: t("nav.corrections", "Corrections"),
            href: "/attendance/corrections",
            icon: FilePenLine,
            show: true,
          },
          {
            label: t("nav.leave", "Leave"),
            href: "/leave",
            icon: CalendarDays,
            show: true,
          },
          {
            label: t("nav.approvals", "Approvals"),
            href: "/approvals",
            icon: CheckSquare,
            show: isSupervisor,
          },
          {
            label: t("nav.payroll", "Payroll Runs"),
            href: "/payroll",
            icon: Wallet,
            show: can.viewPayrollRuns,
          },
          {
            label: t("nav.payslips", "My Payslips"),
            href: "/payroll/payslips",
            icon: Receipt,
            show: true,
          },
          {
            label: t("nav.loans", "Loans"),
            href: "/payroll/loans",
            icon: Banknote,
            show: can.viewPayrollRuns,
          },
          {
            label: t("nav.cost_sharing", "Cost Sharing"),
            href: "/payroll/cost-sharing",
            icon: GraduationCap,
            show: can.viewPayrollRuns,
          },
          {
            label: t("nav.reports", "Reports"),
            href: "/reports",
            icon: BarChart3,
            show: can.viewReports,
          },
          {
            label: t("nav.analytics", "Analytics"),
            href: "/analytics",
            icon: TrendingUp,
            // As the sidebar: the page is for either dashboard ability, and
            // HR, finance and regional users were left out here (audit N85).
            show: can.viewExecutiveDashboard || can.viewRegionalDashboard,
          },
          {
            label: t("nav.announcements", "Announcements"),
            href: "/announcements",
            icon: Megaphone,
            show: true,
          },
          {
            label: t("nav.vacancies", "Vacancies"),
            href: "/vacancies",
            icon: Briefcase,
            show: true,
          },
          {
            label: t("nav.voice", "Voice"),
            href: "/voice",
            icon: MessageCircleHeart,
            show: true,
          },
          {
            label: t("nav.devices", "Devices"),
            href: "/devices",
            icon: Fingerprint,
            show: can.viewDevices,
          },
          {
            label: t("nav.holidays", "Holidays"),
            href: "/settings/holidays",
            icon: Calendar,
            show: can.manageHolidays,
          },
          {
            label: t("nav.settings", "Settings"),
            href: settingsLanding ?? "/settings",
            icon: Settings,
            show: settingsLanding !== null,
          },
          {
            label: t("nav.billing", "Billing"),
            href: "/billing",
            icon: CreditCard,
            show: isTenantAdmin,
          },
          {
            label: t("nav.api_keys", "API Keys"),
            href: "/settings/api-keys",
            icon: KeyRound,
            show: isTenantAdmin,
          },
          {
            label: t("nav.webhooks", "Webhooks"),
            href: "/settings/webhooks",
            icon: Webhook,
            show: isTenantAdmin,
          },
          {
            label: t("nav.audit_log", "Audit Log"),
            href: "/settings/audit-logs",
            icon: ScrollText,
            show: isTenantAdmin,
          },
          {
            label: t("nav.roles", "Roles"),
            href: "/settings/roles",
            icon: Shield,
            keywords: "permissions custom role",
            show: isTenantAdmin,
          },
          {
            label: t("nav.admin", "Admin Console"),
            href: "/admin",
            icon: Shield,
            show: can.viewAdminConsole,
          },
          {
            label: t("nav.tenants", "Tenants"),
            href: "/admin/tenants",
            icon: Building2,
            keywords: "customers organizations subdomain",
            show: can.viewAdminConsole,
          },
          {
            label: t("nav.platform_audit", "Platform Audit Log"),
            href: "/admin/audit",
            icon: ScrollText,
            keywords: "impersonation compliance all tenants",
            show: can.viewAdminConsole,
          },
          {
            label: t("nav.platform_settings", "Platform Settings"),
            href: "/admin/platform-settings",
            icon: Landmark,
            keywords: "bank account payment details",
            show: can.viewAdminConsole,
          },
          {
            label: t("nav.sms_delivery", "SMS delivery"),
            href: "/admin/sms",
            icon: MessageSquareText,
            keywords: "sms text message gateway otp ethio telecom safaricom",
            show: can.viewAdminConsole,
          },
          {
            label: t("nav.plans", "Plans"),
            href: "/admin/plans",
            icon: Layers,
            keywords: "pricing price catalog subscription tiers",
            show: can.viewAdminConsole,
          },
        ],
      },
      {
        heading: t("command.actions", "Quick Actions"),
        items: [
          {
            label: t("command.add_employee", "Add Employee"),
            href: "/employees/new",
            icon: Plus,
            keywords: "create new hire",
            show: can.manageEmployees,
          },
          {
            label: t("command.run_payroll", "Run Payroll"),
            href: "/payroll",
            icon: Play,
            keywords: "process salary",
            show: can.processPayroll,
          },
          {
            label: t("command.request_leave", "Request Leave"),
            // The leave page holds the request form; /leave/new never existed.
            href: "/leave",
            icon: CalendarDays,
            keywords: "time off vacation",
            show: true,
          },
          {
            label: t("command.generate_report", "Generate Report"),
            href: "/reports",
            icon: FileText,
            keywords: "export analytics",
            show: can.viewReports,
          },
        ],
      },
    ],
    [t, can, isSupervisor, isTenantAdmin, settingsLanding],
  );

  const term = search.trim();
  const debounced = useDebounce(term, 200);
  const searching = term.length >= MIN_QUERY_LENGTH;
  const results = useGlobalSearch(debounced, open && searching);
  const data = searching ? results.data : undefined;
  // The answer on screen is for an older query while the next one loads.
  const stale = searching && (debounced !== term || results.isFetching);

  const recent = useRecentSearches(user?.public_id ?? null);
  const { remember } = recent;

  const openRecord = useCallback(
    (entry: RecentEntry) => {
      remember(entry);
      navigate(entry.href);
    },
    [remember, navigate],
  );

  const employees = data?.employees ?? [];
  const attendance = data?.attendance ?? [];
  const leave = data?.leave ?? [];
  const departments = data?.departments ?? [];
  const recordCount =
    employees.length + attendance.length + leave.length + departments.length;

  // Which row Enter opens. When a new answer arrives, the first record is
  // selected, not whatever page link happened to match while it loaded; once
  // the person moves with the arrows, their choice stands until the next
  // answer. Derived here, so no effect has to chase the data.
  const firstRecord =
    employees[0] !== undefined
      ? `employee ${employees[0].id} ${term}`
      : attendance[0] !== undefined
        ? `attendance ${attendance[0].id} ${term}`
        : leave[0] !== undefined
          ? `leave ${leave[0].id} ${term}`
          : departments[0] !== undefined
            ? `department ${departments[0].id} ${term}`
            : undefined;
  const answerKey = `${data?.query ?? ""}|${recordCount}`;
  const [picked, setPicked] = useState<{ key: string; value: string } | null>(
    null,
  );
  const selectedValue =
    picked !== null && picked.key === answerKey ? picked.value : firstRecord;

  return (
    <CommandDialog
      open={open}
      onOpenChange={setPaletteOpen}
      commandProps={
        selectedValue === undefined
          ? undefined
          : {
              value: selectedValue,
              onValueChange: (value) => setPicked({ key: answerKey, value }),
            }
      }
    >
      <div className="relative">
        <CommandInput
          value={search}
          onValueChange={setSearch}
          placeholder={t(
            "command.placeholder",
            "Search people, attendance, leave, departments and pages",
          )}
          aria-describedby="command-palette-hint"
        />
        {stale && (
          <Loader2
            className="absolute right-4 top-1/2 h-4 w-4 -translate-y-1/2 animate-spin text-muted-foreground"
            aria-hidden
          />
        )}
      </div>
      <CommandList className="max-h-[min(60vh,28rem)]">
        <CommandEmpty>
          {searching
            ? t(
                "command.no_results_for",
                "Nothing you can open matches :query.",
                { query: `"${term}"` },
              )
            : t("command.no_results", "No results found.")}
        </CommandEmpty>

        {!searching && recent.items.length > 0 && (
          <>
            <CommandGroup heading={t("command.recent", "Recent")}>
              {recent.items.map((entry) => (
                <ResultRow
                  key={`recent-${entry.kind}-${entry.id}`}
                  value={`recent ${entry.title} ${entry.subtitle ?? ""}`}
                  icon={KIND_ICON[entry.kind]}
                  title={entry.title}
                  subtitle={entry.subtitle}
                  onSelect={() => openRecord(entry)}
                />
              ))}
            </CommandGroup>
            <CommandSeparator />
          </>
        )}

        {searching && employees.length > 0 && (
          <CommandGroup heading={t("command.employees", "Employees")}>
            {employees.map((e) => {
              const subtitle = [e.employee_code, e.position, e.department]
                .filter(Boolean)
                .join(" · ");
              return (
                <ResultRow
                  key={`employee-${e.id}`}
                  // The query is part of the value so cmdk's own filter keeps
                  // these server-matched rows on screen.
                  value={`employee ${e.id} ${term}`}
                  icon={Users}
                  title={e.name}
                  subtitle={subtitle}
                  query={term}
                  onSelect={() =>
                    openRecord({
                      kind: "employee",
                      id: e.id,
                      title: e.name,
                      subtitle,
                      href: `/employees/${e.id}`,
                    })
                  }
                />
              );
            })}
          </CommandGroup>
        )}

        {searching && attendance.length > 0 && (
          <CommandGroup heading={t("command.attendance", "Attendance")}>
            {attendance.map((a) => {
              const subtitle = t(
                "command.attendance_record",
                "Attendance record",
              );
              return (
                <ResultRow
                  key={`attendance-${a.id}`}
                  value={`attendance ${a.id} ${term}`}
                  icon={Clock}
                  title={a.name}
                  subtitle={subtitle}
                  query={term}
                  onSelect={() =>
                    openRecord({
                      kind: "attendance",
                      id: a.id,
                      title: a.name,
                      subtitle,
                      href: `/employees/${a.id}?tab=attendance`,
                    })
                  }
                />
              );
            })}
          </CommandGroup>
        )}

        {searching && leave.length > 0 && (
          <CommandGroup heading={t("command.leave", "Leave")}>
            {leave.map((l) => {
              const title = [l.employee_name, l.leave_type]
                .filter(Boolean)
                .join(" · ");
              const dates =
                l.start_date && l.end_date
                  ? l.start_date === l.end_date
                    ? formatDate(l.start_date)
                    : `${formatDate(l.start_date)} to ${formatDate(l.end_date)}`
                  : "";
              return (
                <ResultRow
                  key={`leave-${l.id}`}
                  value={`leave ${l.id} ${term}`}
                  icon={CalendarDays}
                  title={title}
                  subtitle={dates}
                  query={term}
                  trailing={
                    <StatusBadge status={l.status} className="ml-2 shrink-0" />
                  }
                  onSelect={() =>
                    openRecord({
                      kind: "leave",
                      id: l.id,
                      title,
                      subtitle: dates,
                      href: l.own ? "/leave" : "/leave?tab=team",
                    })
                  }
                />
              );
            })}
          </CommandGroup>
        )}

        {searching && departments.length > 0 && (
          <CommandGroup heading={t("command.departments", "Departments")}>
            {departments.map((d) => (
              <ResultRow
                key={`department-${d.id}`}
                value={`department ${d.id} ${term}`}
                icon={Building2}
                title={d.name}
                subtitle={d.code ?? undefined}
                query={term}
                onSelect={() =>
                  openRecord({
                    kind: "department",
                    id: d.id,
                    title: d.name,
                    subtitle: d.code ?? undefined,
                    href: "/organization?tab=departments",
                  })
                }
              />
            ))}
          </CommandGroup>
        )}

        {searching && recordCount > 0 && <CommandSeparator />}

        {groups.map((group, gi) => {
          const visible = group.items.filter((i) => i.show);
          if (visible.length === 0) return null;
          return (
            <div key={group.heading}>
              {gi > 0 && <CommandSeparator />}
              <CommandGroup heading={group.heading}>
                {visible.map((item) => (
                  <ResultRow
                    key={item.href ?? item.label}
                    value={`${item.label} ${item.keywords ?? ""}`}
                    icon={item.icon}
                    title={item.label}
                    query={term}
                    onSelect={() => item.href && navigate(item.href)}
                  />
                ))}
              </CommandGroup>
            </div>
          );
        })}
      </CommandList>

      <div
        id="command-palette-hint"
        className="flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-border bg-muted/30 px-3 py-2 text-[11px] text-muted-foreground"
      >
        <span className="flex items-center gap-1">
          <Kbd>↑</Kbd>
          <Kbd>↓</Kbd>
          {t("command.hint_navigate", "to move")}
        </span>
        <span className="flex items-center gap-1">
          <Kbd>↵</Kbd>
          {t("command.hint_open", "to open")}
        </span>
        <span className="flex items-center gap-1">
          <Kbd>Esc</Kbd>
          {t("command.hint_close", "to close")}
        </span>
        {!searching && recent.items.length > 0 ? (
          <button
            type="button"
            onClick={recent.clear}
            className="ml-auto rounded px-1 underline-offset-2 hover:text-foreground hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          >
            {t("command.clear_recent", "Clear recent")}
          </button>
        ) : (
          !searching && (
            <span className="ml-auto hidden sm:inline">
              {t(
                "command.hint_min_chars",
                "Type 2 or more letters to search records",
              )}
            </span>
          )
        )}
      </div>
    </CommandDialog>
  );
}

function Kbd({ children }: { children: React.ReactNode }) {
  return (
    <kbd className="inline-flex h-5 min-w-5 items-center justify-center rounded border border-border bg-background px-1 font-mono text-[10px] font-medium text-foreground">
      {children}
    </kbd>
  );
}

/** The part of `text` that matched, in bold; the rest as is. */
function Highlight({ text, query }: { text: string; query?: string }) {
  const q = query?.trim();
  if (!q || q.length < MIN_QUERY_LENGTH) return <>{text}</>;
  const at = text.toLowerCase().indexOf(q.toLowerCase());
  if (at === -1) return <>{text}</>;
  return (
    <>
      {text.slice(0, at)}
      <mark className="rounded-sm bg-transparent font-semibold text-foreground">
        {text.slice(at, at + q.length)}
      </mark>
      {text.slice(at + q.length)}
    </>
  );
}

function ResultRow({
  value,
  icon: Icon,
  title,
  subtitle,
  query,
  trailing,
  onSelect,
}: {
  value: string;
  icon: LucideIcon;
  title: string;
  subtitle?: string;
  query?: string;
  trailing?: React.ReactNode;
  onSelect: () => void;
}) {
  return (
    <CommandItem value={value} onSelect={onSelect} className="gap-3">
      <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md border border-border bg-background">
        <Icon className="!h-4 !w-4 text-muted-foreground" aria-hidden />
      </span>
      <span className="flex min-w-0 flex-1 flex-col">
        <span className="truncate text-sm text-foreground">
          <Highlight text={title} query={query} />
        </span>
        {subtitle && (
          <span className="truncate text-xs text-muted-foreground">
            <Highlight text={subtitle} query={query} />
          </span>
        )}
      </span>
      {trailing}
    </CommandItem>
  );
}
