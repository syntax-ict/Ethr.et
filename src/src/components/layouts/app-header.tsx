"use client";

import Link from "next/link";
import {
  Check,
  Contrast,
  Globe,
  LogOut,
  Menu,
  Monitor,
  Moon,
  Settings,
  Sun,
  User,
} from "lucide-react";
import { useTheme } from "next-themes";
import { Button } from "@/components/ui/button";
import { Avatar, AvatarFallback } from "@/components/ui/avatar";
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
import { Separator } from "@/components/ui/separator";
import { useAppLayout } from "./layout-context";
import { NotificationBell } from "@/features/notifications/components/notification-bell";
import { useCurrentUser, useLogout } from "@/features/auth/api";
import { TenantLogoBadge } from "@/features/branding/TenantBrandingProvider";
import { Badge } from "@/components/ui/badge";
import { useT } from "@/lib/i18n/useT";

const THEME_OPTIONS = [
  { value: "system", icon: Monitor, labelKey: "theme.system" },
  { value: "light", icon: Sun, labelKey: "theme.light" },
  { value: "dark", icon: Moon, labelKey: "theme.dark" },
  { value: "high-contrast", icon: Contrast, labelKey: "theme.high_contrast" },
] as const;

export function AppHeader() {
  const { mobileNavOpen, setMobileNavOpen } = useAppLayout();
  const { theme, setTheme } = useTheme();
  const { data: user } = useCurrentUser();
  const logout = useLogout();

  const { t } = useT();
  const initials = user?.email?.slice(0, 2).toUpperCase() ?? "U";
  const displayName = user?.email?.split("@")[0] ?? "User";
  const roleName = user?.role?.replace(/_/g, " ") ?? "";

  return (
    <>
      <header className="sticky top-0 z-30 flex h-16 shrink-0 items-center justify-between border-b border-border bg-background/95 px-4 backdrop-blur supports-[backdrop-filter]:bg-background/80 lg:px-6">
        <div className="flex items-center gap-3">
          <Button
            variant="ghost"
            size="icon"
            className="lg:hidden"
            onClick={() => setMobileNavOpen(true)}
          >
            <Menu className="h-5 w-5" />
            <span className="sr-only">
              {t("nav.open_menu", "Open menu")}
            </span>
          </Button>
          <div className="flex items-center gap-2 lg:hidden">
            <TenantLogoBadge size="sm" />
          </div>
        </div>

        <div className="flex items-center gap-1">
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="ghost" size="icon">
                <Sun className="h-4 w-4 rotate-0 scale-100 transition-all dark:-rotate-90 dark:scale-0" />
                <Moon className="absolute h-4 w-4 rotate-90 scale-0 transition-all dark:rotate-0 dark:scale-100" />
                <span className="sr-only">
                  {t("theme.toggle", "Toggle theme")}
                </span>
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              <DropdownMenuLabel>
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
                    <Icon className="h-4 w-4" />
                    <span className="flex-1">
                      {t(
                        opt.labelKey,
                        opt.value === "high-contrast"
                          ? "High Contrast"
                          : opt.value.charAt(0).toUpperCase() +
                              opt.value.slice(1),
                      )}
                    </span>
                    {theme === opt.value && (
                      <Check className="h-4 w-4 text-primary" />
                    )}
                  </DropdownMenuItem>
                );
              })}
            </DropdownMenuContent>
          </DropdownMenu>

          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="ghost" size="icon">
                <Globe className="h-4 w-4" />
                <span className="sr-only">
                  {t("language.change", "Change language")}
                </span>
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              <DropdownMenuLabel>Language / ቋንቋ</DropdownMenuLabel>
              <DropdownMenuSeparator />
              <DropdownMenuItem
                onClick={() => {
                  localStorage.setItem("locale", "en");
                  window.location.reload();
                }}
              >
                🇬🇧 English
              </DropdownMenuItem>
              <DropdownMenuItem
                onClick={() => {
                  localStorage.setItem("locale", "am");
                  window.location.reload();
                }}
              >
                🇪🇹 አማርኛ (Amharic)
              </DropdownMenuItem>
              <DropdownMenuItem
                onClick={() => {
                  localStorage.setItem("locale", "om");
                  window.location.reload();
                }}
              >
                🇪🇹 Afaan Oromoo
              </DropdownMenuItem>
              <DropdownMenuItem
                onClick={() => {
                  localStorage.setItem("locale", "ti");
                  window.location.reload();
                }}
              >
                🇪🇹 ትግርኛ
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>

          <NotificationBell />

          <Separator orientation="vertical" className="mx-1 h-6" />

          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="ghost" className="gap-2 px-2">
                <Avatar className="h-7 w-7">
                  <AvatarFallback className="text-xs">
                    {initials}
                  </AvatarFallback>
                </Avatar>
                <span className="hidden text-sm font-medium sm:inline-block">
                  {displayName}
                </span>
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-48">
              <DropdownMenuLabel className="font-normal">
                <p className="text-sm font-medium">{displayName}</p>
                <p className="text-xs text-muted-foreground">{user?.email}</p>
                <Badge
                  variant="outline"
                  className="mt-1 text-[10px] capitalize"
                >
                  {roleName}
                </Badge>
              </DropdownMenuLabel>
              <DropdownMenuSeparator />
              <DropdownMenuItem asChild>
                <Link href="/settings" className="cursor-pointer">
                  <Settings className="mr-2 h-4 w-4" />
                  {t("nav.settings", "Settings")}
                </Link>
              </DropdownMenuItem>
              <DropdownMenuSeparator />
              <DropdownMenuItem
                className="cursor-pointer text-destructive focus:text-destructive"
                onClick={() => logout.mutate()}
              >
                <LogOut className="mr-2 h-4 w-4" />
                {t("common.logout", "Log Out")}
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      </header>

      <Sheet open={mobileNavOpen} onOpenChange={setMobileNavOpen}>
        <div className="flex h-16 items-center gap-2 border-b px-6">
          <TenantLogoBadge />
        </div>
        <div className="py-2">
          <SidebarNav onNavigate={() => setMobileNavOpen(false)} />
        </div>
      </Sheet>
    </>
  );
}
