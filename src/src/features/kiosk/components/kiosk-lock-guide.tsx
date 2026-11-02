"use client";

import { Lock, Smartphone, Tablet, Monitor, UserRound } from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { useT } from "@/lib/i18n/useT";

/**
 * How to finish locking a kiosk. The page itself blocks what a browser lets
 * it block (useKioskLock); the home button and the app switcher belong to the
 * device, so the admin who sets the terminal up is told how to pin it.
 */
export function KioskLockGuide() {
  const { t } = useT();
  const devices = [
    {
      icon: Smartphone,
      title: t("attendance.kiosks_page.lock_android_title"),
      body: t("attendance.kiosks_page.lock_android"),
    },
    {
      icon: Tablet,
      title: t("attendance.kiosks_page.lock_ipad_title"),
      body: t("attendance.kiosks_page.lock_ipad"),
    },
    {
      icon: Monitor,
      title: t("attendance.kiosks_page.lock_windows_title"),
      body: t("attendance.kiosks_page.lock_windows"),
    },
  ];

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-base">
          <Lock className="h-4 w-4 text-primary" aria-hidden="true" />
          {t("attendance.kiosks_page.lock_title")}
        </CardTitle>
      </CardHeader>
      <CardContent className="space-y-4 text-sm">
        <p className="text-muted-foreground">
          {t("attendance.kiosks_page.lock_intro")}
        </p>
        <ul className="grid gap-3 md:grid-cols-3">
          {devices.map(({ icon: Icon, title, body }) => (
            <li key={title} className="rounded-lg border p-3">
              <p className="flex items-center gap-2 font-medium">
                <Icon className="h-4 w-4" aria-hidden="true" /> {title}
              </p>
              <p className="mt-1 text-muted-foreground">{body}</p>
            </li>
          ))}
        </ul>
        <p className="flex items-start gap-2 text-muted-foreground">
          <UserRound className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
          {t("attendance.kiosks_page.self_service_note")}
        </p>
      </CardContent>
    </Card>
  );
}
