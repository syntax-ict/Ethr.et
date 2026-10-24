"use client";

import Link from "next/link";
import { AlertTriangle, CheckCircle2, Info, OctagonAlert } from "lucide-react";
import { Card, CardContent } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import {
  useAttendanceSetupStatus,
  type AttendanceSetupCheck,
} from "@/features/attendance/api";
import { useT } from "@/lib/i18n/useT";
import { cn } from "@/lib/utils";

/**
 * What is keeping attendance from working here, each with a link to where it
 * is fixed. A blocker means punches are rated wrong or refused; a warning
 * means part of the setup will not do what it looks like.
 */
export function SetupStatusPanel({ compact = false }: { compact?: boolean }) {
  const { t } = useT();
  // quiet-on-error: advisory only; a failed check leaves the settings below usable, and the page reports its own load errors.
  const status = useAttendanceSetupStatus();

  const text: Record<string, { title: string; detail: string }> = {
    no_default_schedule: {
      title: t("setup_status.no_default_schedule", "No default shift"),
      detail: t(
        "setup_status.no_default_schedule_desc",
        "People without a shift of their own are not rated late or early. Make one shift the default.",
      ),
    },
    geofence_unplaced: {
      title: t(
        "setup_status.geofence_unplaced",
        "Location required at :count branch(es) not on the map",
      ),
      detail: t(
        "setup_status.geofence_unplaced_desc",
        "Punches there are kept but cannot be checked. Put the branch on the map.",
      ),
    },
    nothing_on_map: {
      title: t(
        "setup_status.nothing_on_map",
        "No branch or site is on the map",
      ),
      detail: t(
        "setup_status.nothing_on_map_desc",
        "Phone check-in works, but no punch can be checked against a place.",
      ),
    },
    crews_unassigned: {
      title: t(
        "setup_status.crews_unassigned",
        ":count rotation(s) with nobody on them",
      ),
      detail: t(
        "setup_status.crews_unassigned_desc",
        "Until crews are assigned, their people are rated against the default shift.",
      ),
    },
    pending_punches: {
      title: t(
        "setup_status.pending_punches",
        ":count punch(es) this week with no shift",
      ),
      detail: t(
        "setup_status.pending_punches_desc",
        "They are not rated late or early. Assign those people a shift.",
      ),
    },
    devices_silent: {
      title: t(
        "setup_status.devices_silent",
        ":count device(s) silent for a day",
      ),
      detail: t(
        "setup_status.devices_silent_desc",
        "Punches made on them have not arrived. Check the device and its connection.",
      ),
    },
    kiosk_pins_missing: {
      title: t(
        "setup_status.kiosk_pins_missing",
        ":count people without a kiosk PIN",
      ),
      detail: t(
        "setup_status.kiosk_pins_missing_desc",
        "Their branch requires a PIN at the kiosk, so they cannot punch there yet.",
      ),
    },
    field_without_sites: {
      title: t(
        "setup_status.field_without_sites",
        ":count field staff and no work sites",
      ),
      detail: t(
        "setup_status.field_without_sites_desc",
        "Add the places they work so their punches can be checked.",
      ),
    },
    holidays_missing: {
      title: t("setup_status.holidays_missing", "Public holidays missing"),
      detail: t(
        "setup_status.holidays_missing_desc",
        "Without them, a holiday counts as an absence. Load them in one click.",
      ),
    },
    working_week_mismatch: {
      title: t(
        "setup_status.working_week_mismatch",
        "Working week and default shift disagree",
      ),
      detail: t(
        "setup_status.working_week_mismatch_desc",
        "Leave is counted on the working week, attendance on the shift's days.",
      ),
    },
    kiosk_none: {
      title: t("setup_status.kiosk_none", "Kiosk check-in is on with no kiosk"),
      detail: t(
        "setup_status.kiosk_none_desc",
        "Register a kiosk, or turn kiosk check-in off.",
      ),
    },
  };

  if (status.isLoading) {
    return <Skeleton className="h-16" />;
  }
  if (!status.data) return null;

  const checks = status.data.checks as AttendanceSetupCheck[];
  if (checks.length === 0) {
    if (compact) return null;
    return (
      <Card>
        <CardContent className="flex items-center gap-3 p-4 text-sm">
          <CheckCircle2 className="h-5 w-5 text-success" aria-hidden="true" />
          {t(
            "setup_status.all_good",
            "Attendance is set up. Nothing needs attention.",
          )}
        </CardContent>
      </Card>
    );
  }

  return (
    <Card>
      <CardContent className="space-y-3 p-4">
        <p className="text-sm font-semibold">
          {t("setup_status.title", "Attendance setup")}
        </p>
        <ul className="space-y-2">
          {checks.map((check) => {
            const Icon =
              check.severity === "blocker"
                ? OctagonAlert
                : check.severity === "warning"
                  ? AlertTriangle
                  : Info;
            const copy = text[check.id];
            if (!copy) return null;
            return (
              <li key={check.id}>
                <Link
                  href={check.fix_route}
                  className="flex items-start gap-3 rounded-md p-2 hover:bg-muted"
                >
                  <Icon
                    className={cn(
                      "mt-0.5 h-4 w-4 shrink-0",
                      check.severity === "blocker"
                        ? "text-destructive"
                        : check.severity === "warning"
                          ? "text-warning-on-soft"
                          : "text-muted-foreground",
                    )}
                    aria-hidden="true"
                  />
                  <span className="min-w-0">
                    <span className="block text-sm font-medium">
                      {copy.title.replace(":count", String(check.count))}
                    </span>
                    {!compact && (
                      <span className="block text-xs text-muted-foreground">
                        {copy.detail}
                      </span>
                    )}
                  </span>
                </Link>
              </li>
            );
          })}
        </ul>
      </CardContent>
    </Card>
  );
}
