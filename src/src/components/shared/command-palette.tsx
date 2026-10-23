"use client";

import { Fragment, useCallback, useEffect, useMemo, useState } from "react";
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
import { isoToday, type NavContext } from "@/lib/navigation/registry";
import { intentScore } from "@/lib/navigation/intent-match";
import {
  actionRows,
  type IntentRow,
  pageRows,
  rankIntents,
} from "@/lib/navigation/palette-rows";

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

/** Rows the server already matched carry this prefix in their cmdk value. */
const RECORD = "record:";

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

  const actions: IntentRow[] = useMemo(() => actionRows(ctx, t), [ctx, t]);

  const pages: IntentRow[] = useMemo(
    () =>
      pageRows(ctx, t).map((row) => {
        // The page's own one-line description, as the breadcrumb bar shows it.
        const path = row.href.split("?")[0];
        const meta = getRouteMeta(path);
        return meta?.description !== undefined
          ? {
              ...row,
              description: t(routeI18nKey(path, "desc"), meta.description),
            }
          : row;
      }),
    [ctx, t],
  );

  const term = search.trim();

  // Matching and order are decided here, not by cmdk. cmdk 1.0.4 sorts by
  // moving DOM nodes, and a row that comes back after a query that hid it is
  // put back where React keeps it: typing "who is late today" over another
  // query left "Who is absent today" first and selected, so Enter opened the
  // wrong list (found 2026-10-22 in the browser). Words, not fuzzy letters:
  // see lib/navigation/intent-match.ts for why.
  const rankedActions = useMemo(
    () => rankIntents(term, actions),
    [term, actions],
  );
  const rankedPages = useMemo(() => rankIntents(term, pages), [term, pages]);
  const shownActions = rankedActions.map((r) => r.row);
  const shownPages = rankedPages.map((r) => r.row);
  // The group holding the best answer comes first, so Enter opens it: "find
  // an employee" is the Employees page, not the Add Employee task.
  const pagesFirst =
    (rankedPages[0]?.score ?? 0) > (rankedActions[0]?.score ?? 0);

  const debounced = useDebounce(term, 200);
  const searching = term.length >= MIN_QUERY_LENGTH;
  const results = useGlobalSearch(debounced, open && searching);
  const data = searching ? results.data : undefined;
  // The answer on screen is for an older query while the next one loads.
  const stale = searching && (debounced !== term || results.isFetching);

  const recent = useRecentSearches(user?.public_id ?? null);
  const { remember } = recent;
  const shownRecent = recent.items.filter(
    (entry) => intentScore(term, [entry.title, entry.subtitle ?? ""]) > 0,
  );

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
  const recentValue = (entry: RecentEntry) =>
    `recent:${entry.kind}:${entry.id}`;
  const firstRow = pagesFirst
    ? (shownPages[0] ?? shownActions[0])
    : (shownActions[0] ?? shownPages[0]);
  const bestValue =
    firstRecord ??
    (!searching && shownRecent[0] ? recentValue(shownRecent[0]) : undefined) ??
    firstRow?.value ??
    "";
  // Always controlled. Left to itself cmdk kept a value from a row that had
  // gone (a person matched while typing, then the next answer had none), so
  // nothing was selected and Enter did nothing (found 2026-10-22).
  const onScreen = new Set<string>([
    ...employees.map((e) => `${RECORD}employee:${e.id}`),
    ...attendance.map((a) => `${RECORD}attendance:${a.id}`),
    ...leave.map((l) => `${RECORD}leave:${l.id}`),
    ...departments.map((d) => `${RECORD}department:${d.id}`),
    ...(searching ? [] : shownRecent.map(recentValue)),
    ...shownActions.map((r) => r.value),
    ...shownPages.map((r) => r.value),
  ]);
  const answerKey = `${data?.query ?? ""}|${recordCount}`;
  const [picked, setPicked] = useState<{ key: string; value: string } | null>(
    null,
  );
  const selectedValue =
    picked !== null && picked.key === answerKey && onScreen.has(picked.value)
      ? picked.value
      : bestValue;

  return (
    <CommandDialog
      open={open}
      onOpenChange={setPaletteOpen}
      commandProps={{
        shouldFilter: false,
        value: selectedValue,
        onValueChange: (value: string) => setPicked({ key: answerKey, value }),
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
          {/* A dead end is the moment someone most needs to see what they
              can do: the tasks open to them, as the empty palette lists. */}
          {actions.length > 0 && (
            <div className="mt-4 px-3">
              <p className="text-xs font-medium text-muted-foreground">
                {t("command.no_results_tasks", "Tasks you can start")}
              </p>
              <ul className="mt-2 flex flex-wrap justify-center gap-1.5">
                {actions.slice(0, 4).map((row) => (
                  <li key={row.value}>
                    <button
                      type="button"
                      onClick={() => navigate(row.href)}
                      className="inline-flex items-center gap-1.5 rounded-md border border-border bg-background px-2.5 py-1 text-xs text-foreground transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    >
                      <row.icon
                        className="h-3.5 w-3.5 text-muted-foreground"
                        aria-hidden="true"
                      />
                      {row.label}
                    </button>
                  </li>
                ))}
              </ul>
            </div>
          )}
        </CommandEmpty>

        {!searching && shownRecent.length > 0 && (
          <>
            <CommandGroup heading={t("command.recent", "Recent")}>
              {shownRecent.map((entry) => (
                <ResultRow
                  key={`recent-${entry.kind}-${entry.id}`}
                  value={`recent:${entry.kind}:${entry.id}`}
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

        {(pagesFirst
          ? [
              { key: "nav", rows: shownPages },
              { key: "actions", rows: shownActions },
            ]
          : [
              { key: "actions", rows: shownActions },
              { key: "nav", rows: shownPages },
            ]
        )
          .filter((group) => group.rows.length > 0)
          .map((group, index) => (
            <Fragment key={group.key}>
              {index > 0 && <CommandSeparator />}
              <CommandGroup
                heading={
                  group.key === "nav"
                    ? t("command.nav", "Navigation")
                    : t("command.actions", "Quick Actions")
                }
              >
                {group.rows.map((row) => (
                  <ResultRow
                    key={row.value}
                    value={row.value}
                    icon={row.icon}
                    title={row.label}
                    context={row.context}
                    subtitle={row.description}
                    query={term}
                    onSelect={() => navigate(row.href)}
                  />
                ))}
              </CommandGroup>
            </Fragment>
          ))}
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
  context,
  subtitle,
  query,
  trailing,
  onSelect,
}: {
  value: string;
  icon: LucideIcon;
  title: string;
  context?: string;
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
