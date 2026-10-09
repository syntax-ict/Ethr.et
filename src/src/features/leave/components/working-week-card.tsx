"use client";

import { useState } from "react";
import { CalendarDays, Loader2, Save } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { useSettings, useUpdateSettings } from "@/features/settings/api";
import { apiErrorMessage } from "@/lib/api/error-message";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useT } from "@/lib/i18n/useT";
import { cn } from "@/lib/utils";
import { toast } from "sonner";

/** ISO weekdays, Monday = 1 … Sunday = 7, the encoding the API stores. */
const DAYS = [
  [1, "weekday.mon", "Mon"],
  [2, "weekday.tue", "Tue"],
  [3, "weekday.wed", "Wed"],
  [4, "weekday.thu", "Thu"],
  [5, "weekday.fri", "Fri"],
  [6, "weekday.sat", "Sat"],
  [7, "weekday.sun", "Sun"],
] as const;

/**
 * The organisation's working week: the days a leave request counts. The API
 * accepted and used `working_days`, but no screen set it, so every
 * organisation counted Monday to Friday, including those that work Saturday
 * (audit N82).
 *
 * Saving is `PUT /settings` (`settings.manage`); the leave-types page is open
 * to `leave.manageTypes`, so anyone without the first sees the week read-only.
 */
export function WorkingWeekCard() {
  const { t } = useT();
  const { can } = usePermissions();
  const { data, isLoading } = useSettings();
  const save = useUpdateSettings();

  const persisted = data?.leave?.working_days ?? [1, 2, 3, 4, 5];
  const [edits, setEdits] = useState<number[] | null>(null);
  const days = edits ?? persisted;
  const isDirty =
    edits !== null &&
    [...edits].sort().join(",") !== [...persisted].sort().join(",");

  function toggle(day: number) {
    const next = days.includes(day)
      ? days.filter((d) => d !== day)
      : [...days, day].sort((a, b) => a - b);
    // At least one day: the API refuses an empty week.
    if (next.length > 0) setEdits(next);
  }

  function saveWeek() {
    save.mutate(
      { working_days: days },
      {
        onSuccess: () => {
          setEdits(null);
          toast.success(t("leave_types_page.week_saved", "Working week saved"));
        },
        onError: (err) =>
          toast.error(
            apiErrorMessage(
              err,
              t(
                "leave_types_page.week_save_failed",
                "Couldn't save the working week",
              ),
            ),
          ),
      },
    );
  }

  return (
    <Card>
      <CardHeader className="flex flex-row items-start justify-between gap-4">
        <div>
          <CardTitle className="text-base flex items-center gap-2">
            <CalendarDays className="h-4 w-4" aria-hidden="true" />
            {t("leave_types_page.working_week", "Working week")}
          </CardTitle>
          <p className="mt-1 text-sm text-muted-foreground">
            {t(
              "leave_types_page.working_week_desc",
              "The days a leave request counts. Holidays are not counted.",
            )}
          </p>
        </div>
        {isDirty && can.manageSettings && (
          <Button onClick={saveWeek} disabled={save.isPending} size="sm">
            {save.isPending ? (
              <Loader2 className="mr-2 h-4 w-4 animate-spin" />
            ) : (
              <Save className="mr-2 h-4 w-4" />
            )}
            {t("common.save", "Save")}
          </Button>
        )}
      </CardHeader>
      <CardContent>
        {isLoading ? (
          <Skeleton className="h-9 w-80" />
        ) : (
          <div
            className="flex flex-wrap gap-2"
            role="group"
            aria-label={t("leave_types_page.working_week", "Working week")}
          >
            {DAYS.map(([day, key, fallback]) => (
              <button
                key={day}
                type="button"
                aria-pressed={days.includes(day)}
                disabled={!can.manageSettings}
                onClick={() => toggle(day)}
                className={cn(
                  "w-12 rounded-md border py-1.5 text-xs font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-70",
                  days.includes(day)
                    ? "border-primary bg-primary text-primary-foreground"
                    : "border-input bg-background text-muted-foreground hover:bg-muted",
                )}
              >
                {t(key, fallback)}
              </button>
            ))}
          </div>
        )}
        {!can.manageSettings && (
          <p className="mt-2 text-xs text-muted-foreground">
            {t(
              "leave_types_page.working_week_admin_only",
              "Only an organisation admin can change the working week.",
            )}
          </p>
        )}
      </CardContent>
    </Card>
  );
}
