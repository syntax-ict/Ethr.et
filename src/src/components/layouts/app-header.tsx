"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import {
  Bell,
  Check,
  Contrast,
  Globe,
  LogOut,
  Menu,
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
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { Sheet } from "@/components/ui/sheet";
import { SidebarNav } from "./sidebar-nav";
import { useAppLayout } from "./layout-context";
import { NotificationBell } from "@/features/notifications/components/notification-bell";
import { openCommandPalette } from "@/components/shared/command-palette";
import { useCurrentUser, useLogout } from "@/features/auth/api";
import { useUpdatePreferences } from "@/features/profile/api";
import { TenantLogoBadge } from "@/features/branding/TenantBrandingProvider";
import { Badge } from "@/components/ui/badge";
import { useT } from "@/lib/i18n/useT";
import { setLocale, supportedLocales } from "@/lib/i18n/translations";
import { useCalendar } from "@/lib/calendar/calendar-context";
import { useShortcutLabel } from "@/lib/hooks/usePlatformShortcut";

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

import { getRouteMeta, routeI18nKey } from "@/lib/route-meta";

function useCurrentPageLabel() {
  const pathname = usePathname();
  const { t } = useT();
  const meta = getRouteMeta(pathname);
  if (!meta) return t("common.page", "Page");
  return t(routeI18nKey(pathname, "label"), meta.label);
}

export function AppHeader() {
  const { mobileNavOpen, setMobileNavOpen } = useAppLayout();
  const { theme, setTheme } = useTheme();
  const { data: user } = useCurrentUser();
  const logout = useLogout();
  const updateLocale = useUpdatePreferences();
  const pageLabel = useCurrentPageLabel();
  const { calendar, toggle: toggleCalendar } = useCalendar();
  const paletteShortcut = useShortcutLabel("K");

  const { t, locale } = useT();
  const displayName =
    user?.name?.split(" ")[0] ?? user?.email?.split("@")[0] ?? "User";
  const roleName = user?.role?.replace(/_/g, " ") ?? "";

  return (
    <>
      <header className="sticky top-0 z-30 flex h-14 shrink-0 items-center border-b border-border bg-background/95 px-4 backdrop-blur supports-[backdrop-filter]:bg-background/80 lg:px-6">
        {/* Left: Mobile menu + mobile logo / Desktop breadcrumbs */}
        <div className="flex min-w-0 flex-1 items-center gap-3">
          <Button
            variant="ghost"
            size="icon"
            className="h-9 w-9 shrink-0 lg:hidden"
            onClick={() => setMobileNavOpen(true)}
          >
            <Menu className="h-5 w-5" />
            <span className="sr-only">{t("nav.open_menu", "Open menu")}</span>
          </Button>
          <div className="flex items-center gap-2 lg:hidden">
            <TenantLogoBadge size="sm" />
          </div>

          {/* Current page label */}
          <span className="hidden truncate text-sm font-medium text-foreground lg:block">
            {pageLabel}
          </span>
        </div>

        {/* Center: Search trigger (desktop only) */}
        <button
          type="button"
          onClick={openCommandPalette}
          className="mx-4 hidden h-8 w-48 items-center gap-2 rounded-lg border border-border bg-muted/50 px-3 text-sm text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring lg:flex xl:w-64"
        >
          <Search className="h-3.5 w-3.5 shrink-0" />
          <span className="flex-1 text-left">
            {t("common.search", "Search")}...
          </span>
          <kbd className="hidden rounded border border-border/60 bg-background px-1.5 py-0.5 font-mono text-[10px] leading-none text-muted-foreground sm:inline-block">
            {paletteShortcut}
          </kbd>
        </button>

        {/* Right: Actions */}
        <div className="flex items-center gap-0.5">
          {/* Mobile search trigger (the full search box is desktop-only) */}
          <Button
            variant="ghost"
            size="icon"
            className="h-9 w-9 lg:hidden"
            onClick={openCommandPalette}
          >
            <Search className="h-4 w-4" />
            <span className="sr-only">{t("common.search", "Search")}</span>
          </Button>

          {/* Theme switcher */}
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="ghost" size="icon" className="h-9 w-9">
                <Sun className="h-4 w-4 rotate-0 scale-100 transition-all dark:-rotate-90 dark:scale-0" />
                <Moon className="absolute h-4 w-4 rotate-90 scale-0 transition-all dark:rotate-0 dark:scale-100" />
                <span className="sr-only">
                  {t("theme.toggle", "Toggle theme")}
                </span>
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-44">
              <DropdownMenuLabel className="text-xs font-medium text-muted-foreground">
                {t("theme.label", "Theme")}
              </DropdownMenuLabel>
              <DropdownMenuSeparator />
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
            </DropdownMenuContent>
          </DropdownMenu>

          {/* Language switcher */}
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="ghost" size="icon" className="h-9 w-9">
                <Globe className="h-4 w-4" />
                <span className="sr-only">
                  {t("language.change", "Change language")}
                </span>
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-48">
              <DropdownMenuLabel className="text-xs font-medium text-muted-foreground">
                {t("language.label", "Language")}
              </DropdownMenuLabel>
              <DropdownMenuSeparator />
              {HEADER_LANGUAGES.map((lang) => (
                <DropdownMenuItem
                  key={lang.code}
                  className="cursor-pointer justify-between"
                  onClick={() => {
                    // Via setLocale, not a raw localStorage write: it validates
                    // the locale, preloads the dictionary and fires
                    // `locale-changed`, which re-renders the app in place. The
                    // old code wrote the key directly and then forced a full
                    // page reload, throwing away any unsaved form state.
                    setLocale(lang.code);
                    // Also stored on the user record so the choice survives a
                    // new device and reaches the payslips, emails and SMS the
                    // server renders. A failure here is not worth interrupting
                    // the switch the user just made.
                    updateLocale.mutate({ locale: lang.code });
                  }}
                >
                  <span>{lang.nativeName}</span>
                  {locale === lang.code && (
                    <Check className="h-3.5 w-3.5 text-primary" />
                  )}
                </DropdownMenuItem>
              ))}
            </DropdownMenuContent>
          </DropdownMenu>

          {/* Calendar system toggle */}
          <Button
            variant="ghost"
            size="icon"
            className="h-9 w-9"
            onClick={toggleCalendar}
            title={
              calendar === "ethiopian"
                ? t(
                    "calendar.ethiopian_active",
                    "Ethiopian Calendar — click for Gregorian",
                  )
                : t(
                    "calendar.gregorian_active",
                    "Gregorian Calendar — click for Ethiopian",
                  )
            }
          >
            <span className="text-xs font-bold leading-none">
              {calendar === "ethiopian"
                ? t("calendar.ec", "EC")
                : t("calendar.gc", "GC")}
            </span>
            <span className="sr-only">
              {t("calendar.toggle", "Toggle calendar system")}
            </span>
          </Button>

          {/* Notifications */}
          <NotificationBell />

          {/* Divider */}
          <div className="mx-1.5 h-5 w-px bg-border" />

          {/* Account menu */}
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="ghost" className="h-9 gap-2 rounded-lg px-2">
                <EmployeeAvatar
                  name={user?.name ?? displayName}
                  photoThumbUrl={user?.photo_thumb_url}
                  className="h-7 w-7"
                  fallbackClassName="bg-primary-soft text-xs font-semibold text-primary-on-soft"
                />
                <span className="hidden text-sm font-medium sm:inline-block">
                  {displayName}
                </span>
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-56">
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
                    <Badge
                      variant="outline"
                      className="mt-1 text-[10px] capitalize"
                    >
                      {roleName}
                    </Badge>
                  </div>
                </div>
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

      {/* Mobile navigation sheet */}
      <Sheet
        open={mobileNavOpen}
        onOpenChange={setMobileNavOpen}
        title={t("nav.main_navigation", "Main navigation")}
      >
        <div className="flex h-14 items-center gap-2 border-b px-6">
          <TenantLogoBadge />
        </div>
        <div className="py-2">
          <SidebarNav onNavigate={() => setMobileNavOpen(false)} />
        </div>
      </Sheet>
    </>
  );
}
