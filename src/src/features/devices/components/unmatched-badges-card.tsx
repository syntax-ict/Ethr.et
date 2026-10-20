"use client";

import { useState } from "react";
import { BadgeHelp, Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { ConfirmDialog } from "@/components/shared/confirm-dialog";
import { EmployeeSelect } from "@/components/shared/employee-select";
import {
  useAssignUnmatchedBadge,
  useDismissUnmatchedBadge,
  useUnmatchedBadges,
  type UnmatchedBadge,
} from "@/features/devices/api";
import { apiErrorDetail } from "@/lib/api/error-message";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";

/**
 * Badges a device sent that no employee matched, with the punches held for
 * them. Until 2026-10-20 those punches were dropped; now assigning the badge
 * replays them, and dismissing drops them. Shown only when there are some.
 */
export function UnmatchedBadgesCard() {
  const { t } = useT();
  const { data } = useUnmatchedBadges();
  const { hasPermission } = usePermissions();
  const canManage = hasPermission("attendance.manage");

  if (!data || data.length === 0) return null;

  return (
    <Card className="border-status-warning/40">
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-base">
          <BadgeHelp className="h-4 w-4 text-status-warning" />
          {t("devices_page.unmatched.title")}
        </CardTitle>
        <p className="text-sm text-muted-foreground">
          {t("devices_page.unmatched.description")}
        </p>
      </CardHeader>
      <CardContent>
        <ul className="divide-y">
          {data.map((row) => (
            <UnmatchedRow
              key={`${row.device_public_id}:${row.badge}`}
              row={row}
              canManage={canManage}
            />
          ))}
        </ul>
      </CardContent>
    </Card>
  );
}

function UnmatchedRow({
  row,
  canManage,
}: {
  row: UnmatchedBadge;
  canManage: boolean;
}) {
  const { t } = useT();
  const { formatDate } = useDateFormatters();
  const [employee, setEmployee] = useState("");
  const [confirmDismiss, setConfirmDismiss] = useState(false);
  const assign = useAssignUnmatchedBadge();
  const dismiss = useDismissUnmatchedBadge();
  const target = { device_public_id: row.device_public_id, badge: row.badge };

  function onAssign() {
    assign.mutate(
      { ...target, employee_public_id: employee },
      {
        onSuccess: (result) =>
          toast.success(
            t("devices_page.unmatched.assigned", undefined, {
              count: result.replayed,
            }),
          ),
        onError: (err: unknown) =>
          toast.error(
            apiErrorDetail(err) || t("devices_page.unmatched.assign_failed"),
          ),
      },
    );
  }

  return (
    <li className="flex flex-col gap-3 py-3 lg:flex-row lg:items-center">
      <div className="min-w-0 flex-1">
        <p className="text-sm font-medium">
          {t("devices_page.unmatched.badge", undefined, { badge: row.badge })}
        </p>
        <p className="text-xs text-muted-foreground">
          {t("devices_page.unmatched.detail", undefined, {
            device: row.device_name,
            count: row.punches,
            date: formatDate(row.last_seen),
          })}
        </p>
      </div>
      {canManage && (
        <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
          <div className="w-full sm:w-64">
            <EmployeeSelect
              id={`unmatched-${row.device_public_id}-${row.badge}`}
              value={employee}
              onChange={setEmployee}
            />
          </div>
          <Button
            size="sm"
            onClick={onAssign}
            disabled={employee === "" || assign.isPending}
          >
            {assign.isPending && (
              <Loader2 className="mr-1 h-4 w-4 animate-spin" />
            )}
            {t("devices_page.unmatched.assign")}
          </Button>
          <Button
            size="sm"
            variant="ghost"
            onClick={() => setConfirmDismiss(true)}
          >
            {t("devices_page.unmatched.dismiss")}
          </Button>
          <ConfirmDialog
            open={confirmDismiss}
            onOpenChange={setConfirmDismiss}
            title={t("devices_page.unmatched.dismiss_title", undefined, {
              badge: row.badge,
            })}
            description={t(
              "devices_page.unmatched.dismiss_description",
              undefined,
              {
                count: row.punches,
              },
            )}
            confirmLabel={t("devices_page.unmatched.dismiss")}
            variant="destructive"
            loading={dismiss.isPending}
            onConfirm={() =>
              dismiss.mutate(target, {
                onSuccess: () => setConfirmDismiss(false),
                onError: (err: unknown) =>
                  toast.error(
                    apiErrorDetail(err) ||
                      t("devices_page.unmatched.dismiss_failed"),
                  ),
              })
            }
          />
        </div>
      )}
    </li>
  );
}
