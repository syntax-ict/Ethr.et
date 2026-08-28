"use client";

import { useState } from "react";
import { CalendarClock, Loader2, Save } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";

interface SettingsResponse {
  payroll?: {
    pay_period?: string;
    run_day?: number;
  };
}

/**
 * Payroll schedule (run day + read-only pay period). These live on the tenant
 * settings JSON and persist via PUT /settings — surfaced here so all payroll
 * configuration sits on the Payroll Rules page instead of the general Settings
 * hub root.
 */
export function PayrollScheduleCard() {
  const { t } = useT();
  const queryClient = useQueryClient();

  const { data, isLoading } = useQuery<SettingsResponse>({
    queryKey: ["settings"],
    queryFn: async () => (await apiClient.get("/settings")).data,
  });

  const persistedRunDay = data?.payroll?.run_day ?? 25;
  const payPeriod = data?.payroll?.pay_period ?? "monthly";

  const [runDay, setRunDay] = useState<number>(persistedRunDay);

  // Adopt the server value once it loads (and after a successful save
  // re-fetch), without clobbering an in-progress edit. Adjusting state during
  // render avoids an extra effect commit — see
  // https://react.dev/learn/you-might-not-need-an-effect.
  const [touched, setTouched] = useState(false);
  if (!touched && runDay !== persistedRunDay) {
    setRunDay(persistedRunDay);
  }

  const save = useMutation({
    mutationFn: async (payload: { run_day: number }) => {
      const { data } = await apiClient.put("/settings", { settings: payload });
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["settings"] });
      setTouched(false);
      toast.success(
        t("payroll_config.schedule_saved", "Payroll schedule saved"),
      );
    },
    onError: () =>
      toast.error(
        t("payroll_config.schedule_save_failed", "Failed to save schedule"),
      ),
  });

  const isDirty = touched && runDay !== persistedRunDay;

  return (
    <Card>
      <CardHeader className="flex flex-row items-start justify-between gap-4">
        <div>
          <CardTitle className="text-base flex items-center gap-2">
            <CalendarClock className="h-4 w-4" />{" "}
            {t("payroll_config.schedule", "Payroll Schedule")}
          </CardTitle>
          <p className="mt-1 text-sm text-muted-foreground">
            {t(
              "payroll_config.schedule_desc",
              "When each payroll run happens.",
            )}
          </p>
        </div>
        {isDirty && (
          <Button
            onClick={() => save.mutate({ run_day: runDay })}
            disabled={save.isPending}
            size="sm"
          >
            {save.isPending ? (
              <Loader2 className="mr-2 h-4 w-4 animate-spin" />
            ) : (
              <Save className="mr-2 h-4 w-4" />
            )}
            {t("common.save", "Save")}
          </Button>
        )}
      </CardHeader>

      <CardContent className="grid gap-4 sm:grid-cols-2">
        {isLoading ? (
          <>
            <Skeleton className="h-16" />
            <Skeleton className="h-16" />
          </>
        ) : (
          <>
            <div>
              <Label htmlFor="pay_period">
                {t("payroll_config.pay_period", "Pay Period")}
              </Label>
              <Input
                id="pay_period"
                value={payPeriod}
                disabled
                className="mt-1"
              />
              <p className="mt-1 text-xs text-muted-foreground">
                {t(
                  "payroll_config.pay_period_help",
                  "Contact support to change the pay period.",
                )}
              </p>
            </div>
            <div>
              <Label htmlFor="run_day">
                {t("payroll_config.run_day", "Payroll Run Day")}
              </Label>
              <Input
                id="run_day"
                type="number"
                min={1}
                max={28}
                value={runDay}
                onChange={(e) => {
                  setTouched(true);
                  setRunDay(parseInt(e.target.value) || 1);
                }}
                className="mt-1"
              />
              <p className="mt-1 text-xs text-muted-foreground">
                {t(
                  "payroll_config.run_day_help",
                  "Day of month to run payroll",
                )}
              </p>
            </div>
          </>
        )}
      </CardContent>
    </Card>
  );
}
