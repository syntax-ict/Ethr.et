"use client";

import { useSearchParams } from "next/navigation";
import { branchesApi, departmentsApi } from "@/features/organization/api";
import { Suspense, useState } from "react";
import { CalendarRange, Plus, Users, Building2, Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { DualCalendarDateInput } from "@/components/shared/dual-calendar-date-input";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { Badge } from "@/components/ui/badge";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { PageHeader } from "@/components/shared/page-header";
import { RoleGate } from "@/components/shared/role-gate";
import {
  useAssignShift,
  useAssignableEmployees,
  useDeleteShiftAssignment,
  useEndShiftAssignment,
  useShiftSchedule,
  useShifts,
  type ShiftAssignment,
} from "@/features/shifts/api";
import { todayIso, toHHMM } from "@/features/shifts/dates";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useT } from "@/lib/i18n/useT";
import { useCalendar } from "@/lib/calendar/calendar-context";
import { toast } from "sonner";

/** The API's `detail`, else its first field error, else `fallback`. */
function apiMessage(err: unknown, fallback: string): string {
  const e = err as {
    response?: {
      data?: { detail?: string; errors?: Record<string, string[]> };
    };
  };
  return (
    e.response?.data?.detail ??
    Object.values(e.response?.data?.errors ?? {})[0]?.[0] ??
    fallback
  );
}

interface AssignmentForm {
  shift_public_id: string;
  assignable_type: "employee" | "department" | "branch";
  assignable_public_id: string;
  effective_from: string;
  effective_to: string;
}

/** A fresh form; built per use so "today" is today, not the day of import. */
function emptyForm(shiftPublicId = ""): AssignmentForm {
  return {
    shift_public_id: shiftPublicId,
    assignable_type: "employee",
    assignable_public_id: "",
    effective_from: todayIso(),
    effective_to: "",
  };
}

const TYPE_ICONS = {
  employee: Users,
  department: Building2,
  branch: Building2,
};

function AssignmentsContent() {
  const { t, locale } = useT();
  const { formatDate: formatCalendarDate } = useCalendar();
  // The card printed raw ISO dates while the form beside it set them in the
  // user's calendar (audit N30). Built from parts, not `new Date(iso)`: that is
  // UTC midnight, which is the previous day anywhere west of UTC.
  const showDate = (iso: string | null) => {
    if (!iso) return "—";
    const [y, m, d] = iso.split("-").map(Number);
    return formatCalendarDate(new Date(y, m - 1, d), locale);
  };
  const TYPE_LABEL: Record<string, string> = {
    Employee: t("shifts_settings_page.employee"),
    Department: t("shifts_settings_page.department"),
    Branch: t("shifts_settings_page.branch"),
  };
  const searchParams = useSearchParams();
  const preselectedShift = searchParams.get("shift") ?? "";

  const [showDialog, setShowDialog] = useState(!!preselectedShift);
  const [form, setForm] = useState<AssignmentForm>(() =>
    emptyForm(preselectedShift),
  );

  const { data: shifts } = useShifts();
  const { data: schedule, isLoading } = useShiftSchedule();
  const { data: employees } = useAssignableEmployees(
    form.assignable_type === "employee",
  );
  const { data: departments } = departmentsApi.useList();
  const { data: branches } = branchesApi.useList();

  const assign = useAssignShift();
  const endAssignment = useEndShiftAssignment();
  const deleteAssignment = useDeleteShiftAssignment();

  // Ending is `shift.update`, which HR admins hold; deleting is `shift.delete`,
  // a tenant-admin grant — offered only where the API would allow it.
  const { hasPermission } = usePermissions();
  const canEnd = hasPermission("shift.update");
  const canDelete = hasPermission("shift.delete");

  const [ending, setEnding] = useState<ShiftAssignment | null>(null);
  const [lastDay, setLastDay] = useState("");

  function openEnd(a: ShiftAssignment) {
    // Today, unless the assignment has not started yet: a last day before
    // the first is refused, so the earliest valid choice is its first day.
    const today = todayIso();
    const from = a.effective_from ?? today;
    setLastDay(from > today ? from : today);
    setEnding(a);
  }

  function submitEnd() {
    if (!ending) return;
    endAssignment.mutate(
      { publicId: ending.public_id, payload: { effective_to: lastDay } },
      {
        onSuccess: () => {
          setEnding(null);
          toast.success(t("shift_assignments_page.ended_success"));
        },
        onError: (err: unknown) =>
          toast.error(apiMessage(err, t("shift_assignments_page.end_failed"))),
      },
    );
  }

  function remove(a: ShiftAssignment) {
    if (!confirm(t("shift_assignments_page.delete_confirm"))) return;
    deleteAssignment.mutate(a.public_id, {
      onSuccess: () => toast.success(t("shift_assignments_page.deleted")),
      // A 409 says the assignment has already taken effect and should be
      // ended instead; the API's own sentence is the useful one.
      onError: (err: unknown) =>
        toast.error(apiMessage(err, t("shift_assignments_page.delete_failed"))),
    });
  }

  function submit() {
    assign.mutate(
      {
        shift_public_id: form.shift_public_id,
        assignable_type: form.assignable_type,
        assignable_public_id: form.assignable_public_id,
        effective_from: form.effective_from,
        ...(form.effective_to ? { effective_to: form.effective_to } : {}),
      },
      {
        onSuccess: () => {
          setShowDialog(false);
          setForm(emptyForm());
          toast.success(t("shift_assignments_page.assigned_success"));
        },
        onError: (err: unknown) =>
          toast.error(apiMessage(err, t("shifts_settings_page.assign_failed"))),
      },
    );
  }

  const assignments = schedule?.data ?? [];
  const shiftList = shifts?.data ?? [];

  // Assignable options based on type
  const assignableOptions: { public_id: string; name: string }[] =
    form.assignable_type === "employee"
      ? (employees?.data ?? [])
      : form.assignable_type === "department"
        ? (departments?.data ?? [])
        : (branches?.data ?? []);

  return (
    <div className="space-y-6">
      <PageHeader
        title={t("shift_assignments_page.title")}
        description={t("shift_assignments_page.description")}
        actions={
          <Button
            onClick={() => {
              setForm(emptyForm());
              setShowDialog(true);
            }}
          >
            <Plus className="mr-2 h-4 w-4" />{" "}
            {t("shifts_settings_page.assign_shift")}
          </Button>
        }
      />

      {/* Current assignments */}
      {isLoading ? (
        <div className="space-y-3">
          {Array.from({ length: 5 }).map((_, i) => (
            <Skeleton key={i} className="h-16" />
          ))}
        </div>
      ) : assignments.length === 0 ? (
        <Card>
          <CardContent className="flex flex-col items-center justify-center py-16 text-center">
            <CalendarRange className="h-12 w-12 text-muted-foreground/40" />
            <p className="mt-3 font-medium">
              {t("shift_assignments_page.no_assignments")}
            </p>
            <p className="mt-1 text-sm text-muted-foreground">
              {t("shift_assignments_page.no_assignments_desc")}
            </p>
            <Button
              className="mt-4"
              onClick={() => {
                setForm(emptyForm());
                setShowDialog(true);
              }}
            >
              <Plus className="mr-2 h-4 w-4" />{" "}
              {t("shift_assignments_page.assign_first")}
            </Button>
          </CardContent>
        </Card>
      ) : (
        <div className="space-y-3">
          {assignments.map((a) => {
            const Icon =
              TYPE_ICONS[
                a.assignable_type?.toLowerCase() as keyof typeof TYPE_ICONS
              ] ?? Users;
            const today = todayIso();
            // `effective_to` is the last day the assignment applies, so it is
            // active through that whole date. Comparing `new Date(effective_to)`
            // (UTC midnight, 03:00 in Addis) with the current instant marked
            // it expired from 03:00 on its own last day.
            const isActive = !a.effective_to || a.effective_to >= today;
            // Not started yet: the only kind the API lets anyone delete. One
            // that has taken effect is history and is ended instead.
            const isUpcoming = !!a.effective_from && a.effective_from > today;
            const assigneeName =
              a.assignee.name ?? t("shift_assignments_page.unknown_assignee");
            return (
              <Card key={a.public_id}>
                <CardContent className="flex items-center gap-4 p-4">
                  <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary/10">
                    <Icon className="h-4 w-4 text-primary" />
                  </div>
                  <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2 flex-wrap">
                      <p className="font-medium">
                        {a.shift?.name ??
                          a.rotation?.name ??
                          t("shift_assignments_page.unknown_shift")}
                      </p>
                      <span className="text-sm text-muted-foreground">
                        {t("shift_assignments_page.for")}
                      </span>
                      <p className="font-medium">{assigneeName}</p>
                      <Badge variant="outline" className="text-xs">
                        {TYPE_LABEL[a.assignable_type] ?? a.assignable_type}
                      </Badge>
                      <Badge
                        variant={isActive ? "success" : "secondary"}
                        className="text-xs"
                      >
                        {!isActive
                          ? t("shift_assignments_page.expired")
                          : isUpcoming
                            ? t("shift_assignments_page.upcoming")
                            : t("webhooks_page.active")}
                      </Badge>
                    </div>
                    <p className="mt-0.5 text-sm text-muted-foreground">
                      {a.shift &&
                        `${toHHMM(a.shift.start_time)} – ${toHHMM(a.shift.end_time)} · `}
                      {t("shift_assignments_page.from")}{" "}
                      {showDate(a.effective_from)}
                      {a.effective_to
                        ? ` ${t("shift_assignments_page.to_lc")} ${showDate(a.effective_to)}`
                        : ` (${t("shift_assignments_page.no_end_date")})`}
                    </p>
                  </div>
                  {isActive && (canEnd || (canDelete && isUpcoming)) && (
                    <div className="flex shrink-0 items-center gap-2">
                      {canEnd && (
                        <Button
                          variant="outline"
                          size="sm"
                          aria-label={`${t("shift_assignments_page.end")} ${assigneeName}`}
                          onClick={() => openEnd(a)}
                        >
                          {t("shift_assignments_page.end")}
                        </Button>
                      )}
                      {canDelete && isUpcoming && (
                        <Button
                          variant="outline"
                          size="sm"
                          aria-label={`${t("common.delete")} ${assigneeName}`}
                          disabled={deleteAssignment.isPending}
                          onClick={() => remove(a)}
                        >
                          {t("common.delete")}
                        </Button>
                      )}
                    </div>
                  )}
                </CardContent>
              </Card>
            );
          })}
        </div>
      )}

      {/* Assign Dialog */}
      <Dialog open={showDialog} onOpenChange={setShowDialog}>
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle>{t("shifts_settings_page.assign_shift")}</DialogTitle>
            <DialogDescription>
              {t("shift_assignments_page.assign_desc")}
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-4">
            {/* Shift */}
            <div>
              <Label htmlFor="shift-required">
                {t("shift_assignments_page.shift_required")}
              </Label>
              <Select
                value={form.shift_public_id}
                onValueChange={(v) =>
                  setForm((f) => ({ ...f, shift_public_id: v }))
                }
              >
                <SelectTrigger id="shift-required" className="mt-1">
                  <SelectValue
                    placeholder={t("shifts_settings_page.select_shift")}
                  />
                </SelectTrigger>
                <SelectContent>
                  {shiftList.map((s) => (
                    <SelectItem key={s.public_id} value={s.public_id}>
                      {s.name} ({toHHMM(s.start_time)}–{toHHMM(s.end_time)})
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            {/* Assignable type */}
            <div>
              <Label htmlFor="assign-type">
                {t("shift_assignments_page.assign_to_required")}
              </Label>
              <Select
                value={form.assignable_type}
                onValueChange={(v) =>
                  setForm((f) => ({
                    ...f,
                    assignable_type: v as AssignmentForm["assignable_type"],
                    assignable_public_id: "",
                  }))
                }
              >
                <SelectTrigger id="assign-type" className="mt-1">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="employee">
                    {t("shift_assignments_page.employee_highest")}
                  </SelectItem>
                  <SelectItem value="department">
                    {t("shifts_settings_page.department")}
                  </SelectItem>
                  <SelectItem value="branch">
                    {t("shift_assignments_page.branch_lowest")}
                  </SelectItem>
                </SelectContent>
              </Select>
              <p className="mt-1 text-xs text-muted-foreground">
                {t("shift_assignments_page.priority_hint")}
              </p>
            </div>

            {/* Assignable entity */}
            <div>
              <Label htmlFor="assign-target">
                {form.assignable_type === "employee"
                  ? t("shifts_settings_page.employee")
                  : form.assignable_type === "department"
                    ? t("shifts_settings_page.department")
                    : t("shifts_settings_page.branch")}{" "}
                *
              </Label>
              <Select
                value={form.assignable_public_id}
                onValueChange={(v) =>
                  setForm((f) => ({ ...f, assignable_public_id: v }))
                }
              >
                <SelectTrigger id="assign-target" className="mt-1">
                  <SelectValue
                    placeholder={`${t("shift_assignments_page.select_prefix")} ${form.assignable_type}`}
                  />
                </SelectTrigger>
                <SelectContent>
                  {assignableOptions.map((o) => (
                    <SelectItem key={o.public_id} value={o.public_id}>
                      {o.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            {/* Date range */}
            <div className="grid grid-cols-2 gap-3">
              <div>
                <Label htmlFor="assign-from">
                  {t("shifts_settings_page.effective_from")} *
                </Label>
                <DualCalendarDateInput
                  id="assign-from"
                  value={form.effective_from}
                  onChange={(v) =>
                    setForm((f) => ({ ...f, effective_from: v }))
                  }
                  className="mt-1"
                />
              </div>
              <div>
                <Label htmlFor="assign-to">
                  {t("shifts_settings_page.effective_to")}
                </Label>
                <DualCalendarDateInput
                  id="assign-to"
                  value={form.effective_to}
                  onChange={(v) => setForm((f) => ({ ...f, effective_to: v }))}
                  min={form.effective_from}
                  className="mt-1"
                />
                <p className="mt-1 text-xs text-muted-foreground">
                  {t("shift_assignments_page.leave_blank_hint")}
                </p>
              </div>
            </div>
          </div>

          <DialogFooter>
            <Button variant="outline" onClick={() => setShowDialog(false)}>
              {t("common.cancel")}
            </Button>
            <Button
              onClick={submit}
              disabled={
                !form.shift_public_id ||
                !form.assignable_public_id ||
                !form.effective_from ||
                assign.isPending
              }
            >
              {assign.isPending && (
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
              )}
              {t("shifts_settings_page.assign_shift")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* End Dialog */}
      <Dialog
        open={ending !== null}
        onOpenChange={(open) => !open && setEnding(null)}
      >
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle>{t("shift_assignments_page.end_title")}</DialogTitle>
            <DialogDescription>
              {t("shift_assignments_page.end_desc")}
            </DialogDescription>
          </DialogHeader>

          <div>
            <Label htmlFor="end-last-day">
              {t("shift_assignments_page.last_day")} *
            </Label>
            <DualCalendarDateInput
              id="end-last-day"
              value={lastDay}
              onChange={setLastDay}
              min={ending?.effective_from ?? undefined}
              className="mt-1"
            />
          </div>

          <DialogFooter>
            <Button variant="outline" onClick={() => setEnding(null)}>
              {t("common.cancel")}
            </Button>
            <Button
              onClick={submitEnd}
              disabled={!lastDay || endAssignment.isPending}
            >
              {endAssignment.isPending && (
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
              )}
              {t("shift_assignments_page.end")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}

export default function ShiftAssignmentsPage() {
  return (
    <RoleGate minRole="hr_admin">
      <Suspense fallback={<Skeleton className="h-64" />}>
        <AssignmentsContent />
      </Suspense>
    </RoleGate>
  );
}
