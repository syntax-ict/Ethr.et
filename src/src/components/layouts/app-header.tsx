"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import {
  Bell,
  Building2,
  Check,
  CircleHelp,
  Contrast,
  Globe,
  Keyboard,
  LifeBuoy,
  LogOut,
  Monitor,
  Moon,
  Search,
  ShieldCheck,
  Sun,
  User,
} from "lucide-react";
import { useTheme } from "next-themes";
import { Button } from "@/components/ui/button";
import { EmployeeAvatar } from "@/components/shared/employee-avatar";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuRadioGroup,
  DropdownMenuRadioItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { InstallAppMenuItem } from "@/components/shared/install-app";
import { NotificationBell } from "@/features/notifications/components/notification-bell";
import { GetHelpDialogHost, openGetHelp } from "@/features/support/components";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { openCommandPalette } from "@/components/shared/command-palette";
import {
  useCurrentTenant,
  useCurrentUser,
  useLogout,
} from "@/features/auth/api";
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@/components/ui/tooltip";
import { openKeyboardShortcuts } from "@/components/shared/keyboard-shortcuts";
import { useUpdatePreferences } from "@/features/profile/api";
import { TenantLogoBadge } from "@/features/branding/TenantBrandingProvider";
import { Badge } from "@/components/ui/badge";
import { useT } from "@/lib/i18n/useT";
import { setLocale, supportedLocales } from "@/lib/i18n/translations";
import {
  useCalendar,
  type CalendarSystem,
} from "@/lib/calendar/calendar-context";
import { useShortcutLabel } from "@/lib/hooks/usePlatformShortcut";
import { useTenantTimezone } from "@/lib/hooks/useTenantTimezone";
import { formatCalendarDate } from "@/lib/utils/date";

const THEME_OPTIONS = [
  { value: "system", icon: Monitor, labelKey: "theme.system" },
  { value: "light", icon: Sun, labelKey: "theme.light" },
  { value: "dark", icon: Moon, labelKey: "theme.dark" },
  { value: "high-contrast", icon: Contrast, labelKey: "theme.high_contrast" },
] as const;

/**
 * Languages offered in the header menu.
 *
 * Derived from `supportedLocales`, never hand-listed. This was previously a
 * second hardcoded array that included the untranslated `om` and `ti` stubs and
 * wrote `localStorage` directly, bypassing `setLocale()` and its guard — so the
 * header could still drop a user into a UI of raw translation keys no matter
 * what the shared switcher allowed.
 *
 * The full switcher, with the rest of the preferences, lives on
 * /profile/preferences.
 */
const HEADER_LANGUAGES = supportedLocales.filter(
  (l) => l.status === "available",
);

const CALENDARS: readonly CalendarSystem[] = ["ethiopian", "gregorian"];

/**
 * Every icon action in the header: one size, muted until hovered. The ghost
 * variant's hover colour is overridden so the set reads as one.
 */
const HEADER_ACTION =
  "h-9 text-muted-foreground hover:text-foreground [&_svg]:h-4 [&_svg]:w-4";

import { getRouteMeta, routeI18nKey, routeI18nPath } from "@/lib/route-meta";

function useCurrentPageLabel() {
  const pathname = usePathname();
  const { t } = useT();
  const meta = getRouteMeta(pathname);
  if (!meta) return t("common.page", "Page");
  return t(routeI18nKey(routeI18nPath(pathname, meta), "label"), meta.label);
}

export function AppHeader() {
  const { theme, setTheme } = useTheme();
  const { data: user } = useCurrentUser();
  const { data: tenant } = useCurrentTenant();
  const logout = useLogout();
  const pageLabel = useCurrentPageLabel();
  // Get help belongs to an organisation's people. A platform super admin is
  // the one answering, in the console.
  const { isSuperAdmin } = usePermissions();
  const canAskForHelp = !isSuperAdmin && !!user;
  const paletteShortcut = useShortcutLabel("K");

  const { t } = useT();
  const displayName =
    user?.name?.split(" ")[0] ?? user?.email?.split("@")[0] ?? "User";
  const roleName = user?.role
    ? t(`role.${user.role}`, user.role.replace(/_/g, " "))
    : "";

  return (
    <>
      {/* Three columns on desktop: the search sits in a centred column of its
          own, whatever the page title beside it measures. The actions column
          is never narrower than its contents, so a long name or another
          language cannot push the icons over the search; the title column
          gives way first. Below lg it stays a row: logo, then the icons.
          The search stops at 28rem: each icon or word added to the actions
          comes out of the title column, and at 1280px the Amharic title
          "Add Employee" is the first to clip (Get help cost 6px; naming the
          language and calendar in words cost 6px more). */}
      <header className="sticky top-0 z-30 flex h-14 shrink-0 items-center border-b border-border bg-background/95 px-4 backdrop-blur supports-[backdrop-filter]:bg-background/80 lg:grid lg:grid-cols-[minmax(0,1fr)_minmax(16rem,28rem)_minmax(max-content,1fr)] lg:gap-4 lg:px-6 xl:gap-6">
        {/* Left: mobile logo / Desktop breadcrumbs. The hamburger that lived
            here was the second of two controls opening the same navigation —
            the bottom bar's More is the documented, thumb-reachable one, and
            two openers with two separate sheets meant duplicated state and a
            duplicated control (sidebar audit, 2026-10-16). */}
        <div className="flex min-w-0 flex-1 items-center gap-3">
          {/* min-w-0 lets a long organisation name truncate instead of
              wrapping over the logo and pushing the icons aside (N34). */}
          <div className="flex min-w-0 items-center gap-2 lg:hidden">
            <TenantLogoBadge size="sm" />
          </div>

          {/* Current page label */}
          <span className="hidden truncate text-sm font-medium text-foreground lg:block">
            {pageLabel}
          </span>
        </div>

        {/* Center: the global search (desktop). It opens the palette, which
            searches people, attendance, leave, departments and pages at once. */}
        <button
          type="button"
          onClick={openCommandPalette}
          aria-haspopup="dialog"
          aria-keyshortcuts="Control+K Meta+K /"
          className="group hidden h-9 w-full items-center gap-2.5 rounded-lg border border-border bg-muted/40 px-3 text-sm text-muted-foreground shadow-sm transition-colors hover:border-border-strong hover:bg-muted hover:text-foreground focus-visible:border-interactive-primary focus-visible:bg-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40 lg:flex"
        >
          <Search className="h-4 w-4 shrink-0 transition-colors group-hover:text-foreground" />
          <span className="min-w-0 flex-1 truncate text-left">
            {t(
              "header.search_placeholder",
              "Search people, attendance, leave and pages",
            )}
          </span>
          <kbd className="pointer-events-none hidden shrink-0 rounded border border-border bg-background px-1.5 py-0.5 font-mono text-[10px] font-medium leading-none text-muted-foreground xl:inline-block">
            {paletteShortcut}
          </kbd>
        </button>

        {/* Right: Actions */}
        <div className="flex shrink-0 items-center gap-0.5 lg:justify-self-end">
          {/* Mobile search trigger (the full search box is desktop-only) */}
          <Button
            variant="ghost"
            size="icon"
            className={`${HEADER_ACTION} w-9 lg:hidden`}
            onClick={openCommandPalette}
            aria-haspopup="dialog"
          >
            <Search aria-hidden />
            <span className="sr-only">{t("common.search", "Search")}</span>
          </Button>

          {/* The header keeps what people reach for daily: language and
              calendar, notifications and their account. The theme is set once
              and lives in the account menu, where phones can reach it too
              (2026-10-19). */}
          <LanguageCalendarMenu />

          {/* Get help */}
          {canAskForHelp && (
            <Tooltip>
              <TooltipTrigger asChild>
                <Button
                  variant="ghost"
                  size="icon"
                  className={`${HEADER_ACTION} w-9`}
                  onClick={openGetHelp}
                  aria-haspopup="dialog"
                >
                  <CircleHelp aria-hidden />
                  <span className="sr-only">
                    {t("support.get_help", "Get help")}
                  </span>
                </Button>
              </TooltipTrigger>
              <TooltipContent>
                {t("support.get_help", "Get help")}
              </TooltipContent>
            </Tooltip>
          )}

          {/* Notifications */}
          <NotificationBell />

          {/* Divider */}
          <div className="mx-1.5 h-5 w-px bg-border" />

          {/* Account menu */}
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button
                variant="ghost"
                className="h-9 gap-2 rounded-lg px-1.5 sm:pr-2.5"
              >
                <EmployeeAvatar
                  name={user?.name ?? displayName}
                  photoThumbUrl={user?.photo_thumb_url}
                  className="h-7 w-7"
                  fallbackClassName="bg-primary-soft text-xs font-semibold text-primary-on-soft"
                />
                <span className="hidden min-w-0 flex-col items-start text-left leading-tight sm:flex">
                  <span className="max-w-[9rem] truncate text-sm font-medium">
                    {displayName}
                  </span>
                  <span className="hidden max-w-[9rem] truncate text-[11px] text-muted-foreground xl:block">
                    {roleName}
                  </span>
                </span>
                {/* After the visible name, so the spoken name contains it. */}
                <span className="sr-only">
                  {t("header.account_menu", "Account menu")}
                </span>
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-64">
              <DropdownMenuLabel className="font-normal">
                <div className="flex items-center gap-3">
                  <EmployeeAvatar
                    name={user?.name ?? displayName}
                    photoThumbUrl={user?.photo_thumb_url}
                    className="h-9 w-9"
                    fallbackClassName="bg-primary-soft text-sm font-semibold text-primary-on-soft"
                  />
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-semibold">
                      {user?.name ?? displayName}
                    </p>
                    <p className="truncate text-xs text-muted-foreground">
                      {user?.email}
                    </p>
                    <Badge variant="outline" className="mt-1 text-[10px]">
                      {roleName}
                    </Badge>
                  </div>
                </div>
                {tenant?.name && (
                  <p className="mt-2 flex items-center gap-1.5 truncate text-xs text-muted-foreground">
                    <Building2 className="h-3.5 w-3.5 shrink-0" aria-hidden />
                    <span className="truncate">{tenant.name}</span>
                  </p>
                )}
              </DropdownMenuLabel>
              <DropdownMenuSeparator />
              <DropdownMenuItem asChild>
                <Link href="/profile" className="cursor-pointer gap-2">
                  <User className="h-4 w-4 text-muted-foreground" />
                  {t("nav.my_profile", "My Profile")}
                </Link>
              </DropdownMenuItem>
              <DropdownMenuItem asChild>
                <Link href="/profile/security" className="cursor-pointer gap-2">
                  <ShieldCheck className="h-4 w-4 text-muted-foreground" />
                  {t("nav.security", "Security")}
                </Link>
              </DropdownMenuItem>
              <DropdownMenuItem asChild>
                <Link
                  href="/notifications/preferences"
                  className="cursor-pointer gap-2"
                >
                  <Bell className="h-4 w-4 text-muted-foreground" />
                  {t("nav.notification_prefs", "Notification Preferences")}
                </Link>
              </DropdownMenuItem>
              {canAskForHelp && (
                <DropdownMenuItem asChild>
                  <Link href="/support" className="cursor-pointer gap-2">
                    <LifeBuoy className="h-4 w-4 text-muted-foreground" />
                    {t("nav.support", "Help requests")}
                  </Link>
                </DropdownMenuItem>
              )}
              <InstallAppMenuItem />
              <DropdownMenuItem
                className="cursor-pointer gap-2"
                onClick={openKeyboardShortcuts}
              >
                <Keyboard className="h-4 w-4 text-muted-foreground" />
                <span className="flex-1">
                  {t("shortcuts.title", "Keyboard shortcuts")}
                </span>
                <kbd className="rounded border border-border bg-muted px-1.5 font-mono text-[10px] text-muted-foreground">
                  ?
                </kbd>
              </DropdownMenuItem>
              <DropdownMenuSeparator />
              <DropdownMenuLabel className="text-xs font-medium text-muted-foreground">
                {t("theme.label", "Theme")}
              </DropdownMenuLabel>
              {THEME_OPTIONS.map((opt) => {
                const Icon = opt.icon;
                return (
                  <DropdownMenuItem
                    key={opt.value}
                    onClick={() => setTheme(opt.value)}
                    className="cursor-pointer gap-2"
                  >
                    <Icon className="h-4 w-4 text-muted-foreground" />
                    <span className="flex-1 text-sm">
                      {t(
                        opt.labelKey,
                        opt.value === "high-contrast"
                          ? "High Contrast"
                          : opt.value.charAt(0).toUpperCase() +
                              opt.value.slice(1),
                      )}
                    </span>
                    {theme === opt.value && (
                      <Check className="h-3.5 w-3.5 text-primary" />
                    )}
                  </DropdownMenuItem>
                );
              })}
              <DropdownMenuSeparator />
              <DropdownMenuItem
                className="cursor-pointer gap-2 text-destructive focus:text-destructive"
                onClick={() => logout.mutate()}
              >
                <LogOut className="h-4 w-4" />
                {t("common.logout", "Log Out")}
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      </header>
      {canAskForHelp && <GetHelpDialogHost />}
    </>
  );
}

/**
 * Language and calendar, in one menu.
 *
 * The calendar used to be a separate button that showed "ኢ.ቀ" or "GC" and
 * switched on a single click, explained only by a hover tooltip phones never
 * show. Here both choices are named in words, the current one is announced as
 * a checked radio item, and each calendar previews today's date before it is
 * chosen.
 *
 * Both choices are applied at once and then stored on the user, as the
 * Preferences page does through the same context: a localStorage-only switch
 * vanished on the next device and disagreed with the profile setting (N33). A
 * failed save is not worth interrupting the switch the user just made.
 */
function LanguageCalendarMenu() {
  const { t, locale } = useT();
  const { calendar, setCalendar } = useCalendar();
  const updatePreferences = useUpdatePreferences();
  const timeZone = useTenantTimezone();

  const languageName =
    HEADER_LANGUAGES.find((lang) => lang.code === locale)?.nativeName ?? locale;
  const calendarName = (system: CalendarSystem) =>
    system === "ethiopian"
      ? t("profile.calendar.ethiopian", "Ethiopian")
      : t("profile.calendar.gregorian", "Gregorian");
  const state = `${languageName} · ${calendarName(calendar)}`;
  const today = new Date().toISOString();

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button
          variant="ghost"
          className={`${HEADER_ACTION} min-w-9 gap-1.5 px-2`}
        >
          <Globe aria-hidden />
          <span className="sr-only">
            {t("header.language_calendar", "Language and calendar")}
          </span>{" "}
          {/* Read on phones too, where only the globe shows. */}
          <span className="sr-only text-xs font-medium sm:not-sr-only">
            {state}
          </span>
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-64">
        <DropdownMenuLabel className="text-xs font-medium text-muted-foreground">
          {t("language.label", "Language")}
        </DropdownMenuLabel>
        <DropdownMenuRadioGroup
          value={locale}
          onValueChange={(code) => {
            // Via setLocale, not a raw localStorage write: it validates the
            // locale, preloads the dictionary and fires `locale-changed`,
            // which re-renders the app in place without a reload. Also stored
            // on the user so the payslips, emails and SMS the server renders
            // follow it.
            const lang = HEADER_LANGUAGES.find((l) => l.code === code);
            if (!lang) return;
            setLocale(lang.code);
            updatePreferences.mutate({ locale: lang.code });
          }}
        >
          {HEADER_LANGUAGES.map((lang) => (
            <DropdownMenuRadioItem
              key={lang.code}
              value={lang.code}
              lang={lang.code}
              className="cursor-pointer"
            >
              {lang.nativeName}
            </DropdownMenuRadioItem>
          ))}
        </DropdownMenuRadioGroup>
        <DropdownMenuSeparator />
        <DropdownMenuLabel className="text-xs font-medium text-muted-foreground">
          {t("profile.calendar", "Calendar")}
        </DropdownMenuLabel>
        <DropdownMenuRadioGroup
          value={calendar}
          onValueChange={(value) => {
            const system = value as CalendarSystem;
            setCalendar(system);
            updatePreferences.mutate({ calendar: system });
          }}
        >
          {CALENDARS.map((system) => (
            <DropdownMenuRadioItem
              key={system}
              value={system}
              className="cursor-pointer"
            >
              <span className="flex-1">{calendarName(system)}</span>
              <span className="text-xs tabular-nums text-muted-foreground">
                {formatCalendarDate(today, timeZone, system, locale)}
              </span>
            </DropdownMenuRadioItem>
          ))}
        </DropdownMenuRadioGroup>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}
