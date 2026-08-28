"use client";

import Link from "next/link";
import { useTheme } from "next-themes";
import {
  Bell,
  Languages,
  Palette,
  CalendarDays,
  ChevronRight,
} from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import {
  useMyProfile,
  useUpdatePreferences,
  type ProfilePreferences,
  type ProfileResponse,
} from "@/features/profile/api";
import { useCurrentTenant } from "@/features/auth/api";
import { useT } from "@/lib/i18n/useT";
import { setLocale } from "@/lib/i18n/translations";
import { toast } from "sonner";

const LANGUAGES = [
  { value: "en", label: "English" },
  { value: "am", label: "አማርኛ" },
  { value: "om", label: "Afaan Oromoo" },
  { value: "ti", label: "ትግርኛ" },
  { value: "so", label: "Soomaali" },
  { value: "sid", label: "Sidaamu Afoo" },
] as const;

const THEMES = ["system", "light", "dark", "high-contrast"] as const;
const CALENDARS = ["gregorian", "ethiopian", "dual"] as const;

export default function ProfilePreferencesPage() {
  const query = useMyProfile();

  return (
    <QueryBoundary query={query}>
      {(profile) => <Preferences profile={profile} />}
    </QueryBoundary>
  );
}

function Preferences({ profile }: { profile: ProfileResponse }) {
  const { t } = useT();
  const { theme, setTheme } = useTheme();
  const { data: tenant } = useCurrentTenant();
  const save = useUpdatePreferences();
  const prefs = profile.preferences;

  async function persist(
    payload: Parameters<typeof save.mutateAsync>[0],
    apply?: () => void,
  ) {
    apply?.();

    try {
      await save.mutateAsync(payload);
      toast.success(t("profile.preferences_saved", "Preference saved."));
    } catch {
      toast.error(t("common.action_failed", "That didn't work. Try again."));
    }
  }

  return (
    <div className="space-y-6">
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2 text-base">
            <Languages className="h-4 w-4 text-muted-foreground" />
            {t("profile.language", "Language")}
          </CardTitle>
        </CardHeader>
        <CardContent className="space-y-2">
          <Label htmlFor="locale">
            {t("profile.interface_language", "Interface language")}
          </Label>
          <Select
            value={prefs?.locale ?? "en"}
            onValueChange={(value) =>
              // Applied locally at once, then stored: the server renders payslips,
              // emails and SMS in this language too, which a localStorage-only
              // switcher never reached.
              void persist({ locale: value }, () => setLocale(value))
            }
          >
            <SelectTrigger id="locale" className="w-full sm:w-64">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {LANGUAGES.map((lang) => (
                <SelectItem key={lang.value} value={lang.value}>
                  {lang.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          <p className="text-xs text-muted-foreground">
            {t(
              "profile.language_hint",
              "Also used for the payslips, emails and SMS we send you.",
            )}
          </p>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2 text-base">
            <Palette className="h-4 w-4 text-muted-foreground" />
            {t("theme.label", "Theme")}
          </CardTitle>
        </CardHeader>
        <CardContent className="space-y-2">
          <Label htmlFor="theme">{t("theme.label", "Theme")}</Label>
          <Select
            value={prefs?.theme ?? theme ?? "system"}
            onValueChange={(value) =>
              void persist({ theme: value }, () => setTheme(value))
            }
          >
            <SelectTrigger id="theme" className="w-full sm:w-64">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {THEMES.map((value) => (
                <SelectItem key={value} value={value}>
                  {t(`theme.${value.replace("-", "_")}`, value)}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </CardContent>
      </Card>

      {tenant && (
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2 text-base">
              <CalendarDays className="h-4 w-4 text-muted-foreground" />
              {t("profile.calendar", "Calendar")}
            </CardTitle>
          </CardHeader>
          <CardContent className="space-y-2">
            <Label htmlFor="calendar">
              {t("profile.date_display", "Date display")}
            </Label>
            <Select
              value={prefs?.calendar ?? "gregorian"}
              onValueChange={(value) =>
                void persist({
                  calendar: value as ProfilePreferences["calendar"],
                })
              }
            >
              <SelectTrigger id="calendar" className="w-full sm:w-64">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {CALENDARS.map((value) => (
                  <SelectItem key={value} value={value}>
                    {t(`profile.calendar.${value}`, value)}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <p className="text-xs text-muted-foreground">
              {t(
                "profile.calendar_hint",
                "Dual shows Gregorian and Ethiopian dates side by side.",
              )}
            </p>
          </CardContent>
        </Card>
      )}

      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2 text-base">
            <Bell className="h-4 w-4 text-muted-foreground" />
            {t("notifications_page.preferences", "Notification preferences")}
          </CardTitle>
        </CardHeader>
        <CardContent>
          <Link
            href="/notifications/preferences"
            className="flex items-center justify-between rounded-lg border border-border-default px-4 py-3 text-sm transition-colors hover:bg-surface-secondary"
          >
            <span className="text-muted-foreground">
              {t(
                "profile.notification_prefs_hint",
                "Choose which notifications you receive and how.",
              )}
            </span>
            <ChevronRight className="h-4 w-4 text-muted-foreground" />
          </Link>
        </CardContent>
      </Card>
    </div>
  );
}
