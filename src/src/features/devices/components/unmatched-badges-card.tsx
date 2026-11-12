"use client";

import { useState } from "react";
import Link from "next/link";
import { BadgeHelp, Download, Loader2, UserPlus } from "lucide-react";
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
import { fetchEmployeeImportTemplate } from "@/features/employees/api";
import { employeeTemplateText } from "@/features/employees/components/import-column-guide";
import { matchReasonLabel } from "@/features/employees/likeness";
import { buildTemplate, XLSX_MIME } from "@/features/onboarding/v2/xlsx-grid";
import { apiErrorDetail, apiErrorMessage } from "@/lib/api/error-message";
import { useOptionalCalendar } from "@/lib/calendar/calendar-context";
import {
  useDateFormatters,
  useTenantTimezone,
} from "@/lib/hooks/useTenantTimezone";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useT } from "@/lib/i18n/useT";
import { saveBlob } from "@/lib/utils/csv-export";
import { todayIn } from "@/lib/utils/date";
import { toast } from "sonner";

/**
 * What a badge has to be to become an employee's badge number: the rule
 * StoreEmployeeRequest applies. The held badge column takes 64 characters of
 * anything, so a badge outside it would be refused on the form it prefills.
 */
const BADGE_NUMBER = /^[A-Za-z0-9-]{1,32}$/;

/**
 * The organisation's date the badge was first held, as `Y-m-d`: the hire
 * date its new record starts from. The server sends the UTC time it was
 * stored. A hire date of today would leave every replayed punch before the
 * person's first expected day.
 */
export function heldSinceDate(firstSeen: string, timeZone: string): string {
  const at = new Date(`${firstSeen.replace(" ", "T")}Z`);
  return Number.isNaN(at.getTime())
    ? firstSeen.slice(0, 10)
    : todayIn(timeZone, at);
}

/**
 * Badges a device sent that no employee matched, with the punches held for
 * them. Until 2026-10-20 those punches were dropped; now assigning the badge
 * replays them, and dismissing drops them. Shown only when there are some.
 *
 * Each badge says what the server makes of it now (2026-11-12): whose it is
 * since someone was imported or given the number, whose it may be, or that
 * nobody has it, when it can become a new employee. Replaying is always a
 * click, never automatic: badge numbers are not unique on an import, and a
 * typo must not record a week of someone else's punches.
 */
export function UnmatchedBadgesCard() {
  const { t } = useT();
  const { data } = useUnmatchedBadges();
  const { hasPermission } = usePermissions();
  const canManage = hasPermission("attendance.manage");
  const canCreate = canManage && hasPermission("employee.create");
  const calendar = useOptionalCalendar()?.calendar ?? "gregorian";
  const [downloading, setDownloading] = useState(false);

  if (!data || data.length === 0) return null;

  // The template with the Badge column filled, one row per badge: names are
  // typed in Excel and the file goes through the import, which shows every
  // person, and anyone they look like, before adding them.
  async function downloadList(rows: UnmatchedBadge[]) {
    setDownloading(true);
    try {
      const spec = await fetchEmployeeImportTemplate();
      const workbook = await buildTemplate(
        spec,
        calendar,
        employeeTemplateText(t),
        rows.map((row) => ({ badge_number: row.badge })),
      );
      saveBlob(
        "ethr-unrecognised-badges.xlsx",
        new Blob([workbook], { type: XLSX_MIME }),
      );
    } catch (err) {
      toast.error(
        apiErrorMessage(
          err,
          t(
            "devices_page.unmatched.download_failed",
            "The list could not be made.",
          ),
        ),
      );
    } finally {
      setDownloading(false);
    }
  }

  const addable = data.filter(
    (row) => row.match?.outcome !== "matched" && BADGE_NUMBER.test(row.badge),
  );

  return (
    <Card id="unmatched" className="scroll-mt-20 border-status-warning/40">
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-base">
          <BadgeHelp className="h-4 w-4 text-status-warning" />
          {t("devices_page.unmatched.title")}
        </CardTitle>
        <p className="text-sm text-muted-foreground">
          {t("devices_page.unmatched.description")}
        </p>
        {canCreate && addable.length > 0 && (
          <div>
            <Button
              size="sm"
              variant="outline"
              onClick={() => downloadList(addable)}
              disabled={downloading}
            >
              {downloading ? (
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
              ) : (
                <Download className="mr-2 h-4 w-4" aria-hidden="true" />
              )}
              {t(
                "devices_page.unmatched.download",
                "Download these badges as a list",
              )}
            </Button>
          </div>
        )}
      </CardHeader>
      <CardContent>
        <ul className="divide-y">
          {data.map((row) => (
            <UnmatchedRow
              key={`${row.device_public_id}:${row.badge}`}
              row={row}
              canManage={canManage}
              canCreate={canCreate}
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
  canCreate,
}: {
  row: UnmatchedBadge;
  canManage: boolean;
  canCreate: boolean;
}) {
  const { t } = useT();
  const { formatDate } = useDateFormatters();
  const timeZone = useTenantTimezone();
  const [employee, setEmployee] = useState("");
  const [confirmDismiss, setConfirmDismiss] = useState(false);
  const assign = useAssignUnmatchedBadge();
  const dismiss = useDismissUnmatchedBadge();
  const target = { device_public_id: row.device_public_id, badge: row.badge };

  const match = row.match ?? null;
  const candidates = match?.candidates ?? [];
  // Whose it is, when the server is sure and the viewer may open them.
  const holder =
    match?.outcome === "matched" && match.employee_public_id
      ? candidates.find(
          (c) => c.employee_public_id === match.employee_public_id,
        )
      : undefined;
  // Who it may be: shown with the reasons, and a person decides.
  const possible =
    !holder && match && match.outcome !== "new" ? candidates : [];
  const isNew = match?.outcome === "new";
  const canBeBadgeNumber = BADGE_NUMBER.test(row.badge);

  function onAssign(employeePublicId: string) {
    assign.mutate(
      { ...target, employee_public_id: employeePublicId },
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

  const newEmployeeHref = `/employees/new?${new URLSearchParams({
    badge: row.badge,
    device: row.device_public_id,
    hire_date: heldSinceDate(row.first_seen, timeZone),
  }).toString()}`;

  return (
    <li className="flex flex-col gap-3 py-3 lg:flex-row lg:items-center">
      <div className="min-w-0 flex-1 space-y-1">
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
        {canManage && holder && (
          <p className="text-sm text-status-success">
            {t("devices_page.unmatched.this_is", "This is :name", {
              name: holder.employee_name,
            })}
          </p>
        )}
        {canManage && possible.length > 0 && (
          <ul className="space-y-0.5 text-xs text-muted-foreground">
            {possible.map((c) => (
              <li key={c.employee_public_id}>
                <button
                  type="button"
                  className="text-primary underline underline-offset-2 hover:no-underline"
                  onClick={() => setEmployee(c.employee_public_id)}
                >
                  {t("devices_page.unmatched.possibly", "Possibly :name", {
                    name: c.employee_name,
                  })}
                </button>{" "}
                · {Math.round(c.score * 100)}%
                {c.reasons.length > 0 && (
                  <span className="italic">
                    {" "}
                    ({c.reasons.map((r) => matchReasonLabel(r, t)).join(", ")})
                  </span>
                )}
              </li>
            ))}
          </ul>
        )}
        {canCreate && isNew && !canBeBadgeNumber && (
          <p className="text-xs text-muted-foreground">
            {t(
              "devices_page.unmatched.not_a_badge_number",
              "This badge cannot be saved as a badge number, which takes up to 32 letters, digits and hyphens. Give it to someone below, or dismiss it.",
            )}
          </p>
        )}
      </div>
      {canManage && (
        <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
          {holder ? (
            <Button
              size="sm"
              onClick={() => onAssign(holder.employee_public_id)}
              disabled={assign.isPending}
            >
              {assign.isPending && (
                <Loader2 className="mr-1 h-4 w-4 animate-spin" />
              )}
              {t(
                "devices_page.unmatched.assign_replay",
                "Assign and replay :count punches",
                { count: row.punches },
              )}
            </Button>
          ) : (
            <>
              {canCreate && isNew && canBeBadgeNumber && (
                <Button size="sm" variant="outline" asChild>
                  <Link href={newEmployeeHref}>
                    <UserPlus className="mr-1 h-4 w-4" aria-hidden="true" />
                    {t(
                      "devices_page.unmatched.add_new",
                      "Add as a new employee",
                    )}
                  </Link>
                </Button>
              )}
              <div className="w-full sm:w-64">
                <EmployeeSelect
                  id={`unmatched-${row.device_public_id}-${row.badge}`}
                  value={employee}
                  onChange={setEmployee}
                />
              </div>
              <Button
                size="sm"
                onClick={() => onAssign(employee)}
                disabled={employee === "" || assign.isPending}
              >
                {assign.isPending && (
                  <Loader2 className="mr-1 h-4 w-4 animate-spin" />
                )}
                {t("devices_page.unmatched.assign")}
              </Button>
            </>
          )}
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
