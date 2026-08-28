"use client";

import { useState } from "react";
import {
  Clock,
  Plus,
  Loader2,
  Users,
  Building2,
  GitBranch,
  CalendarDays,
  Trash2,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { DualCalendarDateInput } from "@/components/shared/dual-calendar-date-input";
import { Skeleton } from "@/components/ui/skeleton";
import { Badge } from "@/components/ui/badge";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from "@/components/ui/dialog";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { PageHeader } from "@/components/shared/page-header";
import { EmptyState } from "@/components/shared/empty-state";
import { SimpleTable } from "@/components/shared/simple-table";
import { RoleGate } from "@/components/shared/role-gate";
import { FormField } from "@/components/patterns/FormField";
import { FormErrorSummary } from "@/components/patterns/FormErrorSummary";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { Controller } from "react-hook-form";
import { useZodForm } from "@/lib/forms/use-zod-form";
import { rules, fieldMessage, dateRangeRefinement } from "@/lib/forms/rules";
import { z } from "zod";
import { apiClient } from "@/api/client";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";

interface Shift {
  public_id: string;
  name: string;
  start_time: string;
  end_time: string;
  grace_minutes: number;
  is_default: boolean;
  is_active: boolean;
  assignments_count?: number;
}

interface ShiftAssignment {
  shift: Shift | null;
  assignable_type: string;
  effective_from: string | null;
  effective_to: string | null;
  created_at: string;
}

function assignableTypeLabel(
  type: string,
  t: (key: string, fallback?: string) => string,
): string {
  const lower = type.toLowerCase();
  if (lower === "employee") return t("shifts_settings_page.employee");
  if (lower === "department") return t("shifts_settings_page.department");
  if (lower === "branch") return t("shifts_settings_page.branch");
  return type;
}

/** `HH:MM`, what `<input type="time">` yields. */
const TIME = /^([01]\d|2[0-3]):[0-5]\d$/;

const shiftSchema = z.object({
  name: rules.requiredText(255),
  start_time: z.string().regex(TIME, "shifts_settings_page.time_invalid"),
  end_time: z.string().regex(TIME, "shifts_settings_page.time_invalid"),
  // Not bounded against start_time on purpose: a night shift legitimately ends
  // before it starts (22:00 → 06:00), and the attendance engine already treats
  // an end earlier than the start as crossing midnight. Rejecting it here would
  // make every night shift unenterable.
  grace_minutes: rules.integer({ min: 0, max: 240 }),
});
type ShiftValues = z.infer<typeof shiftSchema>;

const assignSchema = z
  .object({
    shift_public_id: rules.select(),
    assignable_type: z.enum(["employee", "department", "branch"]),
    assignable_public_id: rules.select(),
    effective_from: rules.date(),
    effective_to: rules.optionalDate(),
  })
  .superRefine(dateRangeRefinement("effective_from", "effective_to"));
type AssignValues = z.infer<typeof assignSchema>;

export default function ShiftsPage() {
  const { t } = useT();
  const queryClient = useQueryClient();
  const [dialogOpen, setDialogOpen] = useState(false);
  const [assignOpen, setAssignOpen] = useState(false);

  const shiftForm = useZodForm<ShiftValues>({
    schema: shiftSchema,
    defaultValues: {
      name: "",
      start_time: "",
      end_time: "",
      grace_minutes: "15",
    },
  });

  const assignForm = useZodForm<AssignValues>({
    schema: assignSchema,
    defaultValues: {
      shift_public_id: "",
      assignable_type: "employee",
      assignable_public_id: "",
      effective_from: new Date().toISOString().split("T")[0],
      effective_to: "",
    },
  });

  const assignableType = assignForm.watch("assignable_type");

  const { data, isLoading } = useQuery<{ data: Shift[] }>({
    queryKey: ["shifts"],
    queryFn: async () => (await apiClient.get("/shifts")).data,
  });

  const { data: scheduleData, isLoading: scheduleLoading } = useQuery<{
    data: ShiftAssignment[];
  }>({
    queryKey: ["shifts", "schedule"],
    queryFn: async () => (await apiClient.get("/shifts/schedule")).data,
  });

  const { data: employees } = useQuery<{
    data: { public_id: string; name: string; employee_code: string }[];
  }>({
    queryKey: ["employees", "lookup"],
    queryFn: async () =>
      (await apiClient.get("/employees", { params: { per_page: 200 } })).data,
    enabled: assignOpen,
  });

  const { data: departments } = useQuery<{
    data: { public_id: string; name: string }[];
  }>({
    queryKey: ["departments", "lookup"],
    queryFn: async () =>
      (await apiClient.get("/organization/departments")).data,
    enabled: assignOpen && assignableType === "department",
  });

  const { data: branches } = useQuery<{
    data: { public_id: string; name: string }[];
  }>({
    queryKey: ["branches", "lookup"],
    queryFn: async () => (await apiClient.get("/organization/branches")).data,
    enabled: assignOpen && assignableType === "branch",
  });

  const createShift = useMutation({
    mutationFn: async (payload: {
      name: string;
      start_time: string;
      end_time: string;
      grace_minutes: number;
    }) => {
      const { data } = await apiClient.post("/shifts", payload);
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["shifts"] });
      toast.success(t("shifts_settings_page.created"));
      setDialogOpen(false);
      shiftForm.reset();
    },
    // Errors land inline in the dialog now, not in a toast over a closed form.
  });

  const deleteShift = useMutation({
    mutationFn: async (publicId: string) => {
      await apiClient.delete(`/shifts/${publicId}`);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["shifts"] });
      toast.success(t("shifts_settings_page.deleted"));
    },
    onError: (err: unknown) => {
      const e = err as { response?: { data?: { detail?: string } } };
      toast.error(
        e.response?.data?.detail || t("shifts_settings_page.delete_failed"),
      );
    },
  });

  const assignShift = useMutation({
    mutationFn: async (payload: AssignValues) => {
      const { data } = await apiClient.post("/shifts/assign", {
        ...payload,
        effective_to: payload.effective_to || null,
      });
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["shifts"] });
      toast.success(t("shifts_settings_page.assigned"));
      setAssignOpen(false);
      assignForm.reset();
    },
  });

  async function onCreate(values: ShiftValues) {
    await createShift.mutateAsync({
      name: values.name,
      start_time: values.start_time,
      end_time: values.end_time,
      grace_minutes: Number(values.grace_minutes),
    });
  }

  async function onAssign(values: AssignValues) {
    await assignShift.mutateAsync(values);
  }

  function openAssignFor(shiftPublicId: string) {
    assignForm.setValue("shift_public_id", shiftPublicId);
    setAssignOpen(true);
  }

  const shifts = data?.data ?? [];
  const schedule = scheduleData?.data ?? [];

  const assignableOptions =
    assignableType === "employee"
      ? (employees?.data ?? []).map((e) => ({
          value: e.public_id,
          label: `${e.name} (${e.employee_code})`,
        }))
      : assignableType === "department"
        ? (departments?.data ?? []).map((d) => ({
            value: d.public_id,
            label: d.name,
          }))
        : (branches?.data ?? []).map((b) => ({
            value: b.public_id,
            label: b.name,
          }));

  const typeIcon = {
    employee: Users,
    department: Building2,
    branch: GitBranch,
  };

  return (
    <RoleGate minRole="hr_admin">
      <div className="space-y-6">
        <PageHeader
          title={t("shifts_settings_page.title")}
          description={t("shifts_settings_page.description")}
          actions={
            <div className="flex gap-2">
              <Button variant="outline" onClick={() => setAssignOpen(true)}>
                <CalendarDays className="mr-2 h-4 w-4" />{" "}
                {t("shifts_settings_page.assign_shift")}
              </Button>
              <Button onClick={() => setDialogOpen(true)}>
                <Plus className="mr-2 h-4 w-4" />{" "}
                {t("shifts_settings_page.add_shift")}
              </Button>
            </div>
          }
        />

        <Tabs defaultValue="shifts">
          <TabsList>
            <TabsTrigger value="shifts">
              {t("shifts_settings_page.title")}
            </TabsTrigger>
            <TabsTrigger value="schedule">
              {t("shifts_settings_page.schedule")}
            </TabsTrigger>
          </TabsList>

          <TabsContent value="shifts" className="mt-4">
            {isLoading ? (
              <div className="space-y-3">
                {Array.from({ length: 3 }).map((_, i) => (
                  <Skeleton key={i} className="h-12 w-full" />
                ))}
              </div>
            ) : shifts.length === 0 ? (
              <EmptyState
                icon={Clock}
                title={t("shifts_settings_page.no_shifts")}
                description={t("shifts_settings_page.no_shifts_desc")}
              />
            ) : (
              <Card>
                <CardContent className="p-0">
                  <SimpleTable
                    caption={t("shifts_settings_page.title", "Shifts")}
                    headers={[
                      t("common.name"),
                      t("shifts_settings_page.start"),
                      t("shifts_settings_page.end"),
                      t("shifts_settings_page.grace"),
                      t("shifts_settings_page.assignments"),
                    ]}
                    align={["left", "left", "left", "right", "right"]}
                    colClassName={["", "", "", "", "hidden sm:table-cell"]}
                    rows={shifts.map((shift) => ({
                      key: shift.public_id,
                      cells: [
                        <span key="n" className="font-medium">
                          {shift.name}
                          {shift.is_default && (
                            <Badge variant="outline" className="ml-2 text-xs">
                              {t("shifts_settings_page.default")}
                            </Badge>
                          )}
                        </span>,
                        <span key="s" className="text-muted-foreground">
                          {shift.start_time}
                        </span>,
                        <span key="e" className="text-muted-foreground">
                          {shift.end_time}
                        </span>,
                        <span key="g" className="text-muted-foreground">
                          {shift.grace_minutes}m
                        </span>,
                        <span key="a" className="text-muted-foreground">
                          {shift.assignments_count ?? "—"}
                        </span>,
                      ],
                      actions: (
                        <div className="flex justify-end gap-1">
                          <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => openAssignFor(shift.public_id)}
                          >
                            <CalendarDays className="mr-1 h-3 w-3" />{" "}
                            {t("shifts_settings_page.assign")}
                          </Button>
                          <Button
                            variant="ghost"
                            size="sm"
                            className="text-destructive hover:text-destructive"
                            onClick={() => {
                              if (
                                confirm(
                                  t("shifts_settings_page.delete_confirm"),
                                )
                              )
                                deleteShift.mutate(shift.public_id);
                            }}
                          >
                            <Trash2 className="h-3 w-3" />
                            <span className="sr-only">
                              {t("common.delete", "Delete")}
                            </span>
                          </Button>
                        </div>
                      ),
                    }))}
                  />
                </CardContent>
              </Card>
            )}
          </TabsContent>

          <TabsContent value="schedule" className="mt-4">
            {scheduleLoading ? (
              <div className="space-y-3">
                {Array.from({ length: 3 }).map((_, i) => (
                  <Skeleton key={i} className="h-12 w-full" />
                ))}
              </div>
            ) : schedule.length === 0 ? (
              <EmptyState
                icon={CalendarDays}
                title={t("shifts_settings_page.no_assignments")}
                description={t("shifts_settings_page.no_assignments_desc")}
              />
            ) : (
              <Card>
                <CardHeader>
                  <CardTitle className="text-base">
                    {t("shifts_settings_page.active_schedule")}
                  </CardTitle>
                </CardHeader>
                <CardContent className="p-0">
                  <SimpleTable
                    caption={t(
                      "shifts_settings_page.active_schedule",
                      "Active schedule",
                    )}
                    headers={[
                      t("shifts_settings_page.shift"),
                      t("shifts_settings_page.assigned_to"),
                      t("shifts_settings_page.from"),
                      t("shifts_settings_page.to"),
                    ]}
                    rows={schedule.map((assignment, i) => {
                      const TypeIcon =
                        typeIcon[
                          assignment.assignable_type.toLowerCase() as keyof typeof typeIcon
                        ] ?? Users;
                      return {
                        key: String(i),
                        cells: [
                          <span key="sh" className="font-medium">
                            {assignment.shift?.name ?? "—"}
                            <span className="ml-2 text-xs text-muted-foreground">
                              {assignment.shift?.start_time}–
                              {assignment.shift?.end_time}
                            </span>
                          </span>,
                          <div
                            key="ty"
                            className="flex items-center gap-2 text-muted-foreground"
                          >
                            <TypeIcon className="h-3.5 w-3.5" />
                            <Badge
                              variant="outline"
                              className="text-xs capitalize"
                            >
                              {assignableTypeLabel(
                                assignment.assignable_type,
                                t,
                              )}
                            </Badge>
                          </div>,
                          <span key="f" className="text-muted-foreground">
                            {assignment.effective_from ?? "—"}
                          </span>,
                          <span key="to" className="text-muted-foreground">
                            {assignment.effective_to ?? (
                              <span className="text-xs italic">
                                {t("shifts_settings_page.ongoing")}
                              </span>
                            )}
                          </span>,
                        ],
                      };
                    })}
                  />
                </CardContent>
              </Card>
            )}
          </TabsContent>
        </Tabs>

        {/* Create shift dialog */}
        <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
          <DialogContent>
            <DialogHeader>
              <DialogTitle>{t("shifts_settings_page.add_shift")}</DialogTitle>
            </DialogHeader>
            <form
              onSubmit={shiftForm.submit(
                onCreate,
                t("shifts_settings_page.create_failed"),
              )}
              className="space-y-4"
              noValidate
            >
              <FormErrorSummary message={shiftForm.rootError} />

              <FormField
                id="shift_name"
                label={t("common.name")}
                required
                error={fieldMessage(
                  t,
                  shiftForm.formState.errors.name?.message,
                )}
              >
                <Input
                  {...shiftForm.register("name")}
                  placeholder={t("shifts_settings_page.name_placeholder")}
                  className="mt-1"
                />
              </FormField>

              <div className="grid grid-cols-2 gap-4">
                <FormField
                  id="shift_start"
                  label={t("shifts_settings_page.start_time")}
                  required
                  error={fieldMessage(
                    t,
                    shiftForm.formState.errors.start_time?.message,
                  )}
                >
                  <Input
                    {...shiftForm.register("start_time")}
                    type="time"
                    className="mt-1"
                  />
                </FormField>

                <FormField
                  id="shift_end"
                  label={t("shifts_settings_page.end_time")}
                  required
                  error={fieldMessage(
                    t,
                    shiftForm.formState.errors.end_time?.message,
                  )}
                >
                  <Input
                    {...shiftForm.register("end_time")}
                    type="time"
                    className="mt-1"
                  />
                </FormField>
              </div>

              <FormField
                id="shift_grace"
                label={t("shifts_settings_page.grace_period")}
                required
                error={fieldMessage(
                  t,
                  shiftForm.formState.errors.grace_minutes?.message,
                )}
              >
                <Input
                  {...shiftForm.register("grace_minutes")}
                  type="number"
                  min="0"
                  className="mt-1"
                />
              </FormField>

              <DialogFooter>
                <Button
                  type="button"
                  variant="outline"
                  onClick={() => setDialogOpen(false)}
                >
                  {t("common.cancel")}
                </Button>
                <Button
                  type="submit"
                  disabled={shiftForm.formState.isSubmitting}
                >
                  {shiftForm.formState.isSubmitting && (
                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                  )}
                  {t("shifts_settings_page.add_shift")}
                </Button>
              </DialogFooter>
            </form>
          </DialogContent>
        </Dialog>

        {/* Assign shift dialog */}
        <Dialog open={assignOpen} onOpenChange={setAssignOpen}>
          <DialogContent>
            <DialogHeader>
              <DialogTitle>
                {t("shifts_settings_page.assign_shift")}
              </DialogTitle>
            </DialogHeader>
            <form
              onSubmit={assignForm.submit(
                onAssign,
                t("shifts_settings_page.assign_failed"),
              )}
              className="space-y-4"
              noValidate
            >
              <FormErrorSummary message={assignForm.rootError} />

              <FormField
                id="assign_shift"
                label={t("shifts_settings_page.shift")}
                required
                error={fieldMessage(
                  t,
                  assignForm.formState.errors.shift_public_id?.message,
                )}
              >
                {(control) => (
                  <Controller
                    name="shift_public_id"
                    control={assignForm.control}
                    render={({ field }) => (
                      <Select
                        value={field.value}
                        onValueChange={field.onChange}
                      >
                        <SelectTrigger {...control} className="mt-1">
                          <SelectValue
                            placeholder={t("shifts_settings_page.select_shift")}
                          />
                        </SelectTrigger>
                        <SelectContent>
                          {shifts.map((s) => (
                            <SelectItem key={s.public_id} value={s.public_id}>
                              {s.name} ({s.start_time}–{s.end_time})
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                    )}
                  />
                )}
              </FormField>

              <FormField
                id="assign_type"
                label={t("shifts_settings_page.assign_to")}
                required
              >
                {(control) => (
                  <Controller
                    name="assignable_type"
                    control={assignForm.control}
                    render={({ field }) => (
                      <Select
                        value={field.value}
                        onValueChange={(v) => {
                          field.onChange(v);
                          // The target list is scoped by type, so a previously
                          // chosen employee is not a valid department. Clearing
                          // it stops a stale id being submitted against the
                          // wrong collection.
                          assignForm.setValue("assignable_public_id", "");
                        }}
                      >
                        <SelectTrigger {...control} className="mt-1">
                          <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                          <SelectItem value="employee">
                            {t("shifts_settings_page.employee")}
                          </SelectItem>
                          <SelectItem value="department">
                            {t("shifts_settings_page.department")}
                          </SelectItem>
                          <SelectItem value="branch">
                            {t("shifts_settings_page.branch")}
                          </SelectItem>
                        </SelectContent>
                      </Select>
                    )}
                  />
                )}
              </FormField>

              <FormField
                id="assign_target"
                label={
                  assignableType === "employee"
                    ? t("shifts_settings_page.employee")
                    : assignableType === "department"
                      ? t("shifts_settings_page.department")
                      : t("shifts_settings_page.branch")
                }
                required
                error={fieldMessage(
                  t,
                  assignForm.formState.errors.assignable_public_id?.message,
                )}
              >
                {(control) => (
                  <Controller
                    name="assignable_public_id"
                    control={assignForm.control}
                    render={({ field }) => (
                      <Select
                        value={field.value}
                        onValueChange={field.onChange}
                      >
                        <SelectTrigger {...control} className="mt-1">
                          <SelectValue
                            placeholder={t(
                              "shifts_settings_page.select_ellipsis",
                            )}
                          />
                        </SelectTrigger>
                        <SelectContent>
                          {assignableOptions.map((opt) => (
                            <SelectItem key={opt.value} value={opt.value}>
                              {opt.label}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                    )}
                  />
                )}
              </FormField>

              <div className="grid grid-cols-2 gap-4">
                <FormField
                  id="assign_from"
                  label={t("shifts_settings_page.effective_from")}
                  required
                  error={fieldMessage(
                    t,
                    assignForm.formState.errors.effective_from?.message,
                  )}
                >
                  {(control) => (
                    <Controller
                      name="effective_from"
                      control={assignForm.control}
                      render={({ field }) => (
                        <DualCalendarDateInput
                          {...control}
                          value={field.value}
                          onChange={field.onChange}
                          className="mt-1"
                        />
                      )}
                    />
                  )}
                </FormField>

                <FormField
                  id="assign_to_date"
                  label={
                    <>
                      {t("shifts_settings_page.effective_to")}{" "}
                      <span className="text-xs text-muted-foreground">
                        ({t("leave_page.optional")})
                      </span>
                    </>
                  }
                  error={fieldMessage(
                    t,
                    assignForm.formState.errors.effective_to?.message,
                  )}
                >
                  {(control) => (
                    <Controller
                      name="effective_to"
                      control={assignForm.control}
                      render={({ field }) => (
                        <DualCalendarDateInput
                          {...control}
                          value={field.value}
                          onChange={field.onChange}
                          className="mt-1"
                        />
                      )}
                    />
                  )}
                </FormField>
              </div>

              <DialogFooter>
                <Button
                  type="button"
                  variant="outline"
                  onClick={() => setAssignOpen(false)}
                >
                  {t("common.cancel")}
                </Button>
                <Button
                  type="submit"
                  disabled={assignForm.formState.isSubmitting}
                >
                  {assignForm.formState.isSubmitting && (
                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                  )}
                  {t("shifts_settings_page.assign")}
                </Button>
              </DialogFooter>
            </form>
          </DialogContent>
        </Dialog>
      </div>
    </RoleGate>
  );
}
