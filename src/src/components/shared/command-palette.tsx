"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import { useQuery } from "@tanstack/react-query";
import {
  LayoutDashboard,
  Users,
  Clock,
  CalendarDays,
  Wallet,
  BarChart3,
  Settings,
  Building2,
  Receipt,
  FilePenLine,
  Shield,
  CheckSquare,
  Megaphone,
  Contact,
  Banknote,
  GraduationCap,
  Calendar,
  Fingerprint,
  KeyRound,
  Webhook,
  UserCircle,
  ScrollText,
  CreditCard,
  TrendingUp,
  Plus,
  Play,
  FileText,
  Loader2,
  Landmark,
  Layers,
  type LucideIcon,
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
import { EmployeeAvatar } from "@/components/shared/employee-avatar";
import { apiClient } from "@/api/client";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useDebounce } from "@/lib/hooks/useDebounce";
import { useT } from "@/lib/i18n/useT";

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

interface DirectoryPerson {
  public_id: string;
  name: string;
  email: string | null;
  position?: string | null;
  department?: string | null;
  photo_thumb_url: string | null;
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
  const { can, isSupervisor, isFinanceAdmin, isTenantAdmin } = usePermissions();
  const { t } = useT();

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

  // Global people search — the Linear/Rippling ⌘K behaviour. Only for users who
  // can open an employee record (supervisor and above); everyone else keeps the
  // navigation + actions palette. Server-side scoping still applies.
  const debouncedSearch = useDebounce(search.trim(), 200);
  const peopleEnabled = open && isSupervisor && debouncedSearch.length >= 2;
  const peopleQuery = useQuery({
    queryKey: ["command-search", debouncedSearch],
    enabled: peopleEnabled,
    staleTime: 60 * 1000,
    queryFn: async () => {
      const { data } = await apiClient.get<{ data: DirectoryPerson[] }>(
        "/directory",
        { params: { search: debouncedSearch, per_page: 6 } },
      );
      return data.data;
    },
  });
  const people = peopleQuery.data ?? [];

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
            show: isFinanceAdmin,
          },
          {
            label: t("nav.cost_sharing", "Cost Sharing"),
            href: "/payroll/cost-sharing",
            icon: GraduationCap,
            show: isFinanceAdmin,
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
            show: isTenantAdmin,
          },
          {
            label: t("nav.announcements", "Announcements"),
            href: "/announcements",
            icon: Megaphone,
            show: true,
          },
          {
            label: t("nav.devices", "Devices"),
            href: "/devices",
            icon: Fingerprint,
            show: can.manageEmployees,
          },
          {
            label: t("nav.holidays", "Holidays"),
            href: "/settings/holidays",
            icon: Calendar,
            show: can.manageEmployees,
          },
          {
            label: t("nav.settings", "Settings"),
            href: "/settings",
            icon: Settings,
            show: can.manageSettings,
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
            href: "/leave/new",
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
    [t, can, isSupervisor, isFinanceAdmin, isTenantAdmin],
  );

  const showPeopleGroup =
    peopleEnabled && (peopleQuery.isLoading || people.length > 0);

  return (
    <CommandDialog open={open} onOpenChange={setPaletteOpen}>
      <CommandInput
        value={search}
        onValueChange={setSearch}
        placeholder={t("command.placeholder", "Type a command or search...")}
      />
      <CommandList>
        <CommandEmpty>
          {t("command.no_results", "No results found.")}
        </CommandEmpty>

        {showPeopleGroup && (
          <>
            <CommandGroup heading={t("command.people", "People")}>
              {peopleQuery.isLoading && people.length === 0 ? (
                <CommandItem value={`__searching__ ${search}`} disabled>
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                  {t("command.searching", "Searching…")}
                </CommandItem>
              ) : (
                people.map((person) => (
                  <CommandItem
                    // The raw query is part of the value so cmdk's own filter
                    // always keeps these server-matched rows on screen.
                    key={person.public_id}
                    value={`person ${person.name} ${person.email ?? ""} ${search}`}
                    onSelect={() => navigate(`/employees/${person.public_id}`)}
                  >
                    <EmployeeAvatar
                      name={person.name}
                      photoThumbUrl={person.photo_thumb_url}
                      className="mr-2 h-6 w-6"
                      fallbackClassName="text-[10px]"
                    />
                    <span className="truncate">{person.name}</span>
                    {(person.position || person.department) && (
                      <span className="ml-2 truncate text-xs text-muted-foreground">
                        {[person.position, person.department]
                          .filter(Boolean)
                          .join(" · ")}
                      </span>
                    )}
                  </CommandItem>
                ))
              )}
            </CommandGroup>
            <CommandSeparator />
          </>
        )}

        {groups.map((group, gi) => {
          const visible = group.items.filter((i) => i.show);
          if (visible.length === 0) return null;
          return (
            <div key={group.heading}>
              {gi > 0 && <CommandSeparator />}
              <CommandGroup heading={group.heading}>
                {visible.map((item) => {
                  const Icon = item.icon;
                  return (
                    <CommandItem
                      key={item.href ?? item.label}
                      value={`${item.label} ${item.keywords ?? ""}`}
                      onSelect={() => item.href && navigate(item.href)}
                    >
                      <Icon className="mr-2 h-4 w-4" />
                      {item.label}
                    </CommandItem>
                  );
                })}
              </CommandGroup>
            </div>
          );
        })}
      </CommandList>
    </CommandDialog>
  );
}
