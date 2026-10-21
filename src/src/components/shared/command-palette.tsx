"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import {
  Building2,
  CalendarDays,
  ChevronRight,
  Clock,
  Loader2,
  type LucideIcon,
  Users,
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
import { getRouteMeta, routeI18nKey } from "@/lib/route-meta";
import {
  isoToday,
  type NavContext,
  phrasesKey,
  QUICK_ACTIONS,
  resolveHref,
  visibleDestinations,
} from "@/lib/navigation/registry";
import { intentScore, splitPhrases } from "@/lib/navigation/intent-match";

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

/**
 * Rows the server already matched carry this prefix in their cmdk value, so
 * the filter below keeps them without re-judging them.
 */
const RECORD = "record:";

interface PaletteRow {
  /** cmdk value: unique, never shown. */
  value: string;
  href: string;
  label: string;
  /** The group a child destination belongs to ("Attendance" for "Records"). */
  context?: string;
  description?: string;
  /** What the row answers to: its label and the phrases people use for it. */
  phrases: string[];
  icon: LucideIcon;
}

/**
 * What a typed query matches. Words, not fuzzy letters: see
 * lib/navigation/intent-match.ts for why.
 */
function filter(value: string, search: string, keywords?: string[]): number {
  if (value.startsWith(RECORD)) return 1;
  return intentScore(search, keywords ?? []);
}

export function CommandPalette() {
  const [open, setOpen] = useState(false);
  const [search, setSearch] = useState("");
  const router = useRouter();
  const { can, isSupervisor, isTenantAdmin, isSuperAdmin } = usePermissions();
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

  // The same registry the sidebar draws, so the palette never offers a page
  // the sidebar hides. Read when the dialog opens, so "today" is today.
  // `can` is rebuilt on every render of usePermissions; its flags are what
  // matter, so the context keys on them.
  const canKey = JSON.stringify(can);
  const ctx: NavContext = useMemo(
    () => ({
      can,
      isSupervisor,
      isTenantAdmin,
      isSuperAdmin,
      settingsLanding,
      today: isoToday(),
    }),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [canKey, isSupervisor, isTenantAdmin, isSuperAdmin, settingsLanding, open],
  );

  const actions: PaletteRow[] = useMemo(
    () =>
      QUICK_ACTIONS.filter((a) => a.show(ctx)).map((a) => {
        const label = t(a.labelKey, a.labelFallback);
        return {
          value: `action:${a.id}`,
          href: resolveHref(a.href, ctx),
          label,
          description: t(a.descKey, a.descFallback),
          phrases: [label, ...splitPhrases(t(phrasesKey(a.id), ""))],
          icon: a.icon,
        };
      }),
    [ctx, t],
  );

  const pages: PaletteRow[] = useMemo(
    () =>
      visibleDestinations(ctx).map((d) => {
        const href = resolveHref(d.href, ctx);
        const label = t(d.labelKey, d.labelFallback);
        const context = d.context
          ? t(d.context.labelKey, d.context.labelFallback)
          : undefined;
        // The page's own one-line description, as the breadcrumb bar shows it.
        const path = href.split("?")[0];
        const meta = getRouteMeta(path);
        const description =
          meta?.description !== undefined
            ? t(routeI18nKey(path, "desc"), meta.description)
            : undefined;
        return {
          value: `nav:${d.id}`,
          href,
          label,
          context,
          description,
          phrases: [
            context ? `${context} ${label}` : label,
            label,
            ...splitPhrases(t(phrasesKey(d.id), "")),
          ],
          icon: d.icon,
        };
      }),
    [ctx, t],
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
      ? `${RECORD}employee:${employees[0].id}`
      : attendance[0] !== undefined
        ? `${RECORD}attendance:${attendance[0].id}`
        : leave[0] !== undefined
          ? `${RECORD}leave:${leave[0].id}`
          : departments[0] !== undefined
            ? `${RECORD}department:${departments[0].id}`
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
      commandProps={{
        filter,
        ...(selectedValue === undefined
          ? {}
          : {
              value: selectedValue,
              onValueChange: (value: string) =>
                setPicked({ key: answerKey, value }),
            }),
      }}
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
          <p>
            {searching
              ? t(
                  "command.no_results_for",
                  "Nothing you can open matches :query.",
                  { query: `"${term}"` },
                )
              : t("command.no_results", "No results found.")}
          </p>
          <p className="mt-1 text-xs text-muted-foreground">
            {t(
              "command.no_results_hint",
              'Try a page name, a person\'s name, or a task such as "request leave".',
            )}
          </p>
        </CommandEmpty>

        {!searching && recent.items.length > 0 && (
          <>
            <CommandGroup heading={t("command.recent", "Recent")}>
              {recent.items.map((entry) => (
                <ResultRow
                  key={`recent-${entry.kind}-${entry.id}`}
                  value={`recent:${entry.kind}:${entry.id}`}
                  keywords={[entry.title, entry.subtitle ?? ""]}
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
                  value={`${RECORD}employee:${e.id}`}
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
                  value={`${RECORD}attendance:${a.id}`}
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
                  value={`${RECORD}leave:${l.id}`}
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
                value={`${RECORD}department:${d.id}`}
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

        {actions.length > 0 && (
          <CommandGroup heading={t("command.actions", "Quick Actions")}>
            {actions.map((row) => (
              <ResultRow
                key={row.value}
                value={row.value}
                keywords={row.phrases}
                icon={row.icon}
                title={row.label}
                subtitle={row.description}
                query={term}
                onSelect={() => navigate(row.href)}
              />
            ))}
          </CommandGroup>
        )}

        {pages.length > 0 && (
          <>
            <CommandSeparator />
            <CommandGroup heading={t("command.nav", "Navigation")}>
              {pages.map((row) => (
                <ResultRow
                  key={row.value}
                  value={row.value}
                  keywords={row.phrases}
                  icon={row.icon}
                  title={row.label}
                  context={row.context}
                  subtitle={row.description}
                  query={term}
                  onSelect={() => navigate(row.href)}
                />
              ))}
            </CommandGroup>
          </>
        )}
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
  keywords,
  icon: Icon,
  title,
  context,
  subtitle,
  query,
  trailing,
  onSelect,
}: {
  value: string;
  keywords?: string[];
  icon: LucideIcon;
  title: string;
  context?: string;
  subtitle?: string;
  query?: string;
  trailing?: React.ReactNode;
  onSelect: () => void;
}) {
  return (
    <CommandItem
      value={value}
      keywords={keywords}
      onSelect={onSelect}
      className="gap-3"
    >
      <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md border border-border bg-background">
        <Icon className="!h-4 !w-4 text-muted-foreground" aria-hidden />
      </span>
      <span className="flex min-w-0 flex-1 flex-col">
        <span className="flex min-w-0 items-center gap-1 truncate text-sm text-foreground">
          {context && (
            // The group first and muted, as the sidebar's pinned rows do, so
            // "Records" reads as the attendance records.
            <>
              <span className="shrink-0 text-muted-foreground">{context}</span>
              <ChevronRight
                className="!h-3 !w-3 shrink-0 text-muted-foreground"
                aria-hidden="true"
              />
            </>
          )}
          <span className="truncate">
            <Highlight text={title} query={query} />
          </span>
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
