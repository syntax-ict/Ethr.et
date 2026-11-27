"use client";

import { useId, useState } from "react";
import { Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { useAssignPreview } from "@/features/devices/api";
import {
  identityFacts,
  warningText,
} from "@/features/devices/components/suggestion-line";
import { apiErrorDetail } from "@/lib/api/error-message";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { useT } from "@/lib/i18n/useT";

/**
 * The question asked before a held badge is given to a person: who they are,
 * how many held punches go into their attendance and between which days, and
 * what speaks against it, as the server reads it now. Replaying writes
 * attendance, so Assign is never one click.
 *
 * With any warning, the box must be ticked before Confirm enables, and the
 * caller then sends `accept_warnings`; the server checks its own list again
 * and refuses without it. A preview that cannot be read leaves Confirm off.
 * The card mounts it only while a confirmation is open, so the tick starts
 * clear every time. No Radix Select here: a closed one renders every option and froze this page.
 */
export function AssignBadgeDialog({
  open,
  onOpenChange,
  badge,
  deviceName,
  devicePublicId,
  employeePublicId,
  onConfirm,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  badge: string;
  deviceName: string;
  devicePublicId: string;
  employeePublicId: string;
  /** `acceptWarnings` is true only when there were warnings and the box was ticked. */
  onConfirm: (acceptWarnings: boolean) => void;
}) {
  const { t } = useT();
  const { formatDate } = useDateFormatters();
  const checkId = useId();
  const [acknowledged, setAcknowledged] = useState(false);
  const preview = useAssignPreview(
    {
      device_public_id: devicePublicId,
      badge,
      employee_public_id: employeePublicId,
    },
    open,
  );

  const data = preview.data;
  const warnings = data?.warnings ?? [];
  const needsTick = warnings.length > 0;
  const ready = !!data && (!needsTick || acknowledged);

  function punchesText(read: NonNullable<typeof data>): string {
    if (read.punches === 0) {
      return t("devices_page.unmatched.confirm_no_punches");
    }
    const first = read.first_day ? formatDate(read.first_day) : "";
    if (!read.last_day || read.first_day === read.last_day) {
      return t("devices_page.unmatched.confirm_punches_one_day", undefined, {
        count: read.punches,
        first,
      });
    }
    return t("devices_page.unmatched.confirm_punches", undefined, {
      count: read.punches,
      first,
      last: formatDate(read.last_day),
    });
  }

  const facts = data
    ? identityFacts(
        {
          employee_code: data.employee.employee_code,
          department: data.employee.department,
          position: data.employee.position,
          branch: data.employee.branch,
          hire_date: data.employee.hire_date,
          status: data.employee.status,
          badge_number: data.employee.badge_number,
          warnings: [],
        },
        data.employee.department,
        t,
        formatDate,
      )
    : [];

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>
            {t("devices_page.unmatched.confirm_title", undefined, {
              badge,
              name: data?.employee.name ?? "...",
            })}
          </DialogTitle>
          <DialogDescription>
            {t("devices_page.unmatched.confirm_intro", undefined, {
              badge,
              device: deviceName,
            })}
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-3 text-sm" aria-live="polite">
          {preview.isPending && (
            <p className="flex items-center gap-2 text-muted-foreground">
              <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />
              {t("devices_page.unmatched.confirm_loading")}
            </p>
          )}

          {preview.isError && (
            <div className="space-y-2" role="alert">
              <p className="text-destructive">
                {apiErrorDetail(preview.error) ||
                  t("devices_page.unmatched.confirm_failed")}
              </p>
              <Button
                size="sm"
                variant="outline"
                onClick={() => void preview.refetch()}
                disabled={preview.isFetching}
              >
                {preview.isFetching && (
                  <Loader2 className="mr-1 h-4 w-4 animate-spin" />
                )}
                {t("devices_page.unmatched.confirm_retry")}
              </Button>
            </div>
          )}

          {data && (
            <>
              <div className="space-y-0.5">
                <p className="font-medium">{data.employee.name}</p>
                {facts.length > 0 && (
                  <p className="text-xs text-muted-foreground">
                    {facts.join(" · ")}
                  </p>
                )}
              </div>

              <p>{punchesText(data)}</p>

              {needsTick && (
                <div className="space-y-2 rounded-md border border-status-warning/40 p-3">
                  <p className="font-medium text-status-warning">
                    {t("devices_page.unmatched.confirm_warnings")}
                  </p>
                  {warnings.map((warning) => (
                    <p
                      key={warning.code}
                      className="text-status-warning"
                      data-warning={warning.code}
                    >
                      {warningText(warning, t, formatDate)}
                    </p>
                  ))}
                  <div className="flex items-start gap-2 pt-1">
                    <Checkbox
                      id={checkId}
                      checked={acknowledged}
                      onCheckedChange={(value) =>
                        setAcknowledged(value === true)
                      }
                      className="mt-0.5"
                    />
                    <label htmlFor={checkId} className="cursor-pointer">
                      {t("devices_page.unmatched.confirm_acknowledge")}
                    </label>
                  </div>
                </div>
              )}
            </>
          )}
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            {t("common.cancel", "Cancel")}
          </Button>
          <Button disabled={!ready} onClick={() => onConfirm(needsTick)}>
            {t("devices_page.unmatched.confirm_button")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
