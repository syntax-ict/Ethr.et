"use client";

import { CalendarRange, Loader2 } from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { ErrorState } from "@/components/shared/error-state";
import { useSettings, useUpdateSettings } from "@/features/settings/api";
import { apiErrorMessage } from "@/lib/api/error-message";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";

type LeaveYearKind = "gregorian" | "ethiopian";

/**
 * When leave balances reset: 1 January, or Meskerem 1 on the Ethiopian year.
 * The API refuses a change once leave has been used, requested or carried
 * over, because those balances are numbered in the current year; the select
 * is read-only then and says why.
 */
export function LeaveYearCard() {
  const { t } = useT();
  const { can } = usePermissions();
  const { data, isLoading, isError, refetch } = useSettings();
  const save = useUpdateSettings();

  const current = data?.leave?.leave_year ?? "gregorian";
  const changeable = data?.leave?.leave_year_changeable ?? false;
  const editable = can.manageSettings && changeable;

  function choose(value: string) {
    if (value === current) return;
    save.mutate(
      { leave_year: value as LeaveYearKind },
      {
        onSuccess: () =>
          toast.success(
            t("leave_types_page.leave_year_saved", "Leave year saved"),
          ),
        onError: (err) =>
          toast.error(
            apiErrorMessage(
              err,
              t(
                "leave_types_page.leave_year_save_failed",
                "Couldn't save the leave year",
              ),
            ),
          ),
      },
    );
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base flex items-center gap-2">
          <CalendarRange className="h-4 w-4" aria-hidden="true" />
          {t("leave_types_page.leave_year", "Leave year")}
        </CardTitle>
        <p className="mt-1 text-sm text-muted-foreground">
          {t(
            "leave_types_page.leave_year_desc",
            "When leave balances start again and unused days carry over.",
          )}
        </p>
      </CardHeader>
      <CardContent>
        {isLoading ? (
          <Skeleton className="h-9 w-72" />
        ) : isError ? (
          <ErrorState compact onRetry={() => refetch()} />
        ) : (
          <div className="max-w-sm space-y-2">
            <Label htmlFor="leave_year" className="sr-only">
              {t("leave_types_page.leave_year", "Leave year")}
            </Label>
            <div className="flex items-center gap-2">
              <Select
                value={current}
                onValueChange={choose}
                disabled={!editable || save.isPending}
              >
                <SelectTrigger id="leave_year">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="ethiopian">
                    {t(
                      "leave_types_page.leave_year_ethiopian",
                      "Ethiopian year, Meskerem to Pagume",
                    )}
                  </SelectItem>
                  <SelectItem value="gregorian">
                    {t(
                      "leave_types_page.leave_year_gregorian",
                      "Gregorian year, January to December",
                    )}
                  </SelectItem>
                </SelectContent>
              </Select>
              {save.isPending && (
                <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
              )}
            </div>
            {!can.manageSettings ? (
              <p className="text-xs text-muted-foreground">
                {t(
                  "leave_types_page.leave_year_admin_only",
                  "Only an organisation admin can change the leave year.",
                )}
              </p>
            ) : (
              !changeable && (
                <p className="text-xs text-muted-foreground">
                  {t(
                    "leave_types_page.leave_year_locked",
                    "Leave has already been used, requested or carried over in this year, so the leave year can no longer change.",
                  )}
                </p>
              )
            )}
          </div>
        )}
      </CardContent>
    </Card>
  );
}
