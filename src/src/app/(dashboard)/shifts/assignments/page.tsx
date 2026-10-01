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
  useShiftSchedule,
  useShifts,
} from "@/features/shifts/api";
import { todayIso, toHHMM } from "@/features/shifts/dates";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";

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
  const { t } = useT();
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
        onError: (err: unknown) => {
          const e = err as {
            response?: {
              data?: { detail?: string; errors?: Record<string, string[]> };
            };
          };
          const msg =
            e.response?.data?.detail ??
            Object.values(e.response?.data?.errors ?? {})[0]?.[0] ??
            t("shifts_settings_page.assign_failed");
          toast.error(msg);
        },
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
          {assignments.map((a, i) => {
            const Icon =
              TYPE_ICONS[
                a.assignable_type?.toLowerCase() as keyof typeof TYPE_ICONS
              ] ?? Users;
            // `effective_to` is the last day the assignment applies, so it is
            // active through that whole date. Comparing `new Date(effective_to)`
            // (UTC midnight, 03:00 in Addis) with the current instant marked
            // it expired from 03:00 on its own last day.
            const isActive = !a.effective_to || a.effective_to >= todayIso();
            return (
              <Card key={i}>
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
                      <Badge variant="outline" className="text-xs">
                        {TYPE_LABEL[a.assignable_type] ?? a.assignable_type}
                      </Badge>
                      <Badge
                        variant={isActive ? "success" : "secondary"}
                        className="text-xs"
                      >
                        {isActive
                          ? t("webhooks_page.active")
                          : t("shift_assignments_page.expired")}
                      </Badge>
                    </div>
                    <p className="mt-0.5 text-sm text-muted-foreground">
                      {a.shift &&
                        `${toHHMM(a.shift.start_time)} – ${toHHMM(a.shift.end_time)} · `}
                      {t("shift_assignments_page.from")} {a.effective_from}
                      {a.effective_to
                        ? ` ${t("shift_assignments_page.to_lc")} ${a.effective_to}`
                        : ` (${t("shift_assignments_page.no_end_date")})`}
                    </p>
                  </div>
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
