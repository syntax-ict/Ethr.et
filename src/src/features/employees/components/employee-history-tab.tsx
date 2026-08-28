"use client";

import { useState } from "react";
import { Plus, History, Loader2, ArrowRight } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Input } from "@/components/ui/input";
import { DualCalendarDateInput } from "@/components/shared/dual-calendar-date-input";
import { Textarea } from "@/components/ui/textarea";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { EmptyState } from "@/components/shared/empty-state";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import {
  FormField,
  type FormControlProps,
} from "@/components/patterns/FormField";
import { FormErrorSummary } from "@/components/patterns/FormErrorSummary";
import { Controller } from "react-hook-form";
import { useZodForm } from "@/lib/forms/use-zod-form";
import { rules, fieldMessage } from "@/lib/forms/rules";
import { z } from "zod";
import { useT } from "@/lib/i18n/useT";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { formatETB } from "@/lib/utils/currency";
import {
  useEmployeePersonnelActions,
  useRecordPersonnelAction,
  type PersonnelAction,
  type PersonnelActionType,
  type RecordPersonnelActionInput,
} from "@/features/employees/api";
import {
  branchesApi,
  departmentsApi,
  positionsApi,
  gradesApi,
} from "@/features/organization/api";
import { toast } from "sonner";

const ACTION_TYPES: PersonnelActionType[] = [
  "promotion",
  "demotion",
  "transfer",
  "re_designation",
  "salary_step_increment",
  "acting_assignment",
  "delegation",
  "secondment",
  "reassignment",
  "appointment",
];

const TEMPORARY_TYPES: PersonnelActionType[] = [
  "acting_assignment",
  "delegation",
  "secondment",
];

function actionTypeLabel(
  type: PersonnelActionType,
  t: ReturnType<typeof useT>["t"],
) {
  const fallbacks: Record<PersonnelActionType, string> = {
    appointment: "Appointment",
    promotion: "Promotion",
    demotion: "Demotion",
    transfer: "Transfer",
    re_designation: "Re-designation",
    salary_step_increment: "Salary step increment",
    acting_assignment: "Acting assignment",
    delegation: "Delegation",
    secondment: "Secondment",
    reassignment: "Reassignment",
  };
  return t(`employee.history.type.${type}`, fallbacks[type]);
}

/**
 * Turns the stored `{from,to}` snapshot into display rows. Salary is stored in
 * minor units and formatted as ETB; everything else is a label string. Exported
 * for unit testing.
 */
export function describeChanges(
  changes: PersonnelAction["changes"],
  t: ReturnType<typeof useT>["t"],
): Array<{ label: string; from: string; to: string }> {
  const labels: Record<string, string> = {
    position: t("employee.history.field.position", "Position"),
    grade: t("employee.history.field.grade", "Grade"),
    department: t("employee.history.field.department", "Department"),
    branch: t("employee.history.field.branch", "Branch"),
    salary: t("employee.history.field.salary", "Salary"),
    salary_step: t("employee.history.field.step", "Step"),
  };

  const fmt = (key: string, value: string | number | null): string => {
    if (value === null || value === "") return "—";
    if (key === "salary") return formatETB(Number(value));
    return String(value);
  };

  return Object.entries(changes).map(([key, change]) => ({
    label: labels[key] ?? key,
    from: fmt(key, change?.from ?? null),
    to: fmt(key, change?.to ?? null),
  }));
}

export function EmployeeHistoryTab({ employeeId }: { employeeId: string }) {
  const { t } = useT();
  const { can } = usePermissions();
  const query = useEmployeePersonnelActions(employeeId);
  const [dialogOpen, setDialogOpen] = useState(false);

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h2 className="text-lg font-semibold">
          {t("employee.history.title", "Employment History")}
        </h2>
        {can.recordPersonnelAction && (
          <Button size="sm" onClick={() => setDialogOpen(true)}>
            <Plus className="mr-2 h-4 w-4" />
            {t("employee.history.record", "Record action")}
          </Button>
        )}
      </div>

      <QueryBoundary
        query={query}
        empty={
          <EmptyState
            icon={History}
            title={t("employee.history.empty_title", "No recorded actions")}
            description={t(
              "employee.history.empty_desc",
              "Promotions, transfers, acting assignments and step increments will appear here.",
            )}
          />
        }
      >
        {(actions) => <Timeline actions={actions} />}
      </QueryBoundary>

      {can.recordPersonnelAction && (
        <RecordActionDialog
          employeeId={employeeId}
          open={dialogOpen}
          onOpenChange={setDialogOpen}
        />
      )}
    </div>
  );
}

function Timeline({ actions }: { actions: PersonnelAction[] }) {
  const { t } = useT();
  return (
    <ol className="space-y-3">
      {actions.map((action) => {
        const rows = describeChanges(action.changes, t);
        return (
          <li key={action.public_id}>
            <Card>
              <CardContent className="p-4">
                <div className="flex flex-wrap items-center gap-2">
                  <Badge variant="outline">
                    {actionTypeLabel(action.type, t)}
                  </Badge>
                  {action.is_temporary && (
                    <Badge variant="outline" className="text-[10px]">
                      {t("employee.history.temporary", "Temporary")}
                    </Badge>
                  )}
                  <span className="text-sm text-muted-foreground">
                    {action.effective_date}
                    {action.end_date ? ` → ${action.end_date}` : ""}
                  </span>
                  {action.reference_number && (
                    <span className="ml-auto font-mono text-xs text-muted-foreground">
                      {action.reference_number}
                    </span>
                  )}
                </div>

                {rows.length > 0 && (
                  <div className="mt-3 space-y-1">
                    {rows.map((row) => (
                      <div
                        key={row.label}
                        className="flex items-center gap-2 text-sm"
                      >
                        <span className="w-24 shrink-0 text-muted-foreground">
                          {row.label}
                        </span>
                        <span className="text-muted-foreground/70">
                          {row.from}
                        </span>
                        <ArrowRight className="h-3 w-3 shrink-0 text-muted-foreground" />
                        <span className="font-medium text-foreground">
                          {row.to}
                        </span>
                      </div>
                    ))}
                  </div>
                )}

                {action.reason && (
                  <p className="mt-2 text-sm text-muted-foreground">
                    {action.reason}
                  </p>
                )}
                {action.recorded_by && (
                  <p className="mt-2 text-xs text-muted-foreground">
                    {t("employee.history.recorded_by", "Recorded by")}{" "}
                    {action.recorded_by}
                  </p>
                )}
              </CardContent>
            </Card>
          </li>
        );
      })}
    </ol>
  );
}

const optionalAmount = z
  .string()
  .trim()
  .refine((v) => v === "" || /^\d+(\.\d{1,2})?$/.test(v), "validation.amount");

const actionSchema = z
  .object({
    type: z.enum(
      ACTION_TYPES as [PersonnelActionType, ...PersonnelActionType[]],
    ),
    effective_date: rules.date(),
    end_date: rules.optionalDate(),
    reference_number: rules.text(100),
    reason: rules.text(2000),
    grade_public_id: z.string(),
    position_public_id: z.string(),
    department_public_id: z.string(),
    branch_public_id: z.string(),
    new_salary: optionalAmount,
    salary_step: z
      .string()
      .trim()
      .refine(
        (v) =>
          v === "" || (/^\d+$/.test(v) && Number(v) >= 1 && Number(v) <= 100),
        "employee.history.step_range",
      ),
  })
  .superRefine((data, ctx) => {
    // Acting, delegation and secondment are temporary postings: without an end
    // date the service treats them as permanent and applies the change for
    // good, which is the opposite of what "acting" means.
    if (TEMPORARY_TYPES.includes(data.type) && data.end_date === "") {
      ctx.addIssue({
        code: "custom",
        path: ["end_date"],
        message: "employee.history.end_date_required",
      });
    }
    if (
      data.end_date !== "" &&
      data.effective_date !== "" &&
      data.end_date < data.effective_date
    ) {
      ctx.addIssue({
        code: "custom",
        path: ["end_date"],
        message: "validation.date_range",
      });
    }
  });
type ActionValues = z.infer<typeof actionSchema>;

const EMPTY_ACTION: ActionValues = {
  type: "promotion",
  effective_date: "",
  end_date: "",
  reference_number: "",
  reason: "",
  grade_public_id: "",
  position_public_id: "",
  department_public_id: "",
  branch_public_id: "",
  new_salary: "",
  salary_step: "",
};

function RecordActionDialog({
  employeeId,
  open,
  onOpenChange,
}: {
  employeeId: string;
  open: boolean;
  onOpenChange: (v: boolean) => void;
}) {
  const { t } = useT();
  const record = useRecordPersonnelAction(employeeId);
  const { data: grades } = gradesApi.useList();
  const { data: positions } = positionsApi.useList();
  const { data: departments } = departmentsApi.useList();
  const { data: branches } = branchesApi.useList();

  const {
    register,
    control,
    submit,
    reset,
    watch,
    rootError,
    formState: { errors, isSubmitting },
  } = useZodForm<ActionValues>({
    schema: actionSchema,
    defaultValues: EMPTY_ACTION,
  });

  const isTemporary = TEMPORARY_TYPES.includes(watch("type"));

  async function onSubmit(values: ActionValues) {
    const payload: RecordPersonnelActionInput = {
      type: values.type,
      effective_date: values.effective_date,
      end_date: values.end_date || null,
      reference_number: values.reference_number || null,
      reason: values.reason || null,
      grade_public_id: values.grade_public_id || null,
      position_public_id: values.position_public_id || null,
      department_public_id: values.department_public_id || null,
      branch_public_id: values.branch_public_id || null,
      new_salary_cents: values.new_salary
        ? Math.round(Number(values.new_salary) * 100)
        : null,
      salary_step: values.salary_step ? Number(values.salary_step) : null,
    };

    // `PersonnelActionService` resolves the salary step against the *target*
    // grade's scale and rejects an amount that contradicts it, naming the
    // expected figure. That message is worth landing on the salary field rather
    // than in a toast.
    await record.mutateAsync(payload);

    toast.success(t("employee.history.recorded", "Action recorded"));
    reset(EMPTY_ACTION);
    onOpenChange(false);
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-lg">
        <DialogHeader>
          <DialogTitle>
            {t("employee.history.dialog_title", "Record personnel action")}
          </DialogTitle>
        </DialogHeader>

        <form
          onSubmit={submit(
            onSubmit,
            t("employee.history.record_failed", "Could not record action"),
          )}
          className="space-y-3"
          noValidate
        >
          <FormErrorSummary message={rootError} />

          <div className="grid grid-cols-2 gap-3">
            <Field
              id="action_type"
              label={t("employee.history.field.type", "Action type")}
              required
              error={fieldMessage(t, errors.type?.message)}
            >
              {(control_) => (
                <Controller
                  name="type"
                  control={control}
                  render={({ field }) => (
                    <Select value={field.value} onValueChange={field.onChange}>
                      <SelectTrigger {...control_}>
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        {ACTION_TYPES.map((type) => (
                          <SelectItem key={type} value={type}>
                            {actionTypeLabel(type, t)}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  )}
                />
              )}
            </Field>

            <Field
              id="action_reference"
              label={t("employee.history.field.reference", "Reference no.")}
              error={fieldMessage(t, errors.reference_number?.message)}
            >
              <Input
                {...register("reference_number")}
                placeholder="HR/PROM/2026/014"
              />
            </Field>

            <Field
              id="action_effective_date"
              label={t("employee.history.field.effective", "Effective date")}
              required
              error={fieldMessage(t, errors.effective_date?.message)}
            >
              {(control_) => (
                <Controller
                  name="effective_date"
                  control={control}
                  render={({ field }) => (
                    <DualCalendarDateInput
                      {...control_}
                      value={field.value}
                      onChange={field.onChange}
                    />
                  )}
                />
              )}
            </Field>

            <Field
              id="action_end_date"
              label={t("employee.history.field.end", "End date")}
              required={isTemporary}
              error={fieldMessage(t, errors.end_date?.message)}
            >
              {(control_) => (
                <Controller
                  name="end_date"
                  control={control}
                  render={({ field }) => (
                    <DualCalendarDateInput
                      {...control_}
                      value={field.value}
                      onChange={field.onChange}
                    />
                  )}
                />
              )}
            </Field>

            <Field
              id="action_grade"
              label={t("employee.history.field.grade", "Grade")}
              error={fieldMessage(t, errors.grade_public_id?.message)}
            >
              {(control_) => (
                <Controller
                  name="grade_public_id"
                  control={control}
                  render={({ field }) => (
                    <ResourceSelect
                      {...control_}
                      value={field.value}
                      onChange={field.onChange}
                      options={(grades?.data ?? []).map((g) => ({
                        value: g.public_id,
                        label: g.name,
                      }))}
                      placeholder={t("common.none", "None")}
                    />
                  )}
                />
              )}
            </Field>

            <Field
              id="action_position"
              label={t("employee.history.field.position", "Position")}
              error={fieldMessage(t, errors.position_public_id?.message)}
            >
              {(control_) => (
                <Controller
                  name="position_public_id"
                  control={control}
                  render={({ field }) => (
                    <ResourceSelect
                      {...control_}
                      value={field.value}
                      onChange={field.onChange}
                      options={(positions?.data ?? []).map((p) => ({
                        value: p.public_id,
                        label: p.title,
                      }))}
                      placeholder={t("common.none", "None")}
                    />
                  )}
                />
              )}
            </Field>

            <Field
              id="action_department"
              label={t("employee.history.field.department", "Department")}
              error={fieldMessage(t, errors.department_public_id?.message)}
            >
              {(control_) => (
                <Controller
                  name="department_public_id"
                  control={control}
                  render={({ field }) => (
                    <ResourceSelect
                      {...control_}
                      value={field.value}
                      onChange={field.onChange}
                      options={(departments?.data ?? []).map((d) => ({
                        value: d.public_id,
                        label: d.name,
                      }))}
                      placeholder={t("common.none", "None")}
                    />
                  )}
                />
              )}
            </Field>

            <Field
              id="action_branch"
              label={t("employee.history.field.branch", "Branch")}
              error={fieldMessage(t, errors.branch_public_id?.message)}
            >
              {(control_) => (
                <Controller
                  name="branch_public_id"
                  control={control}
                  render={({ field }) => (
                    <ResourceSelect
                      {...control_}
                      value={field.value}
                      onChange={field.onChange}
                      options={(branches?.data ?? []).map((b) => ({
                        value: b.public_id,
                        label: b.name,
                      }))}
                      placeholder={t("common.none", "None")}
                    />
                  )}
                />
              )}
            </Field>

            <Field
              id="action_new_salary"
              label={t("employee.history.field.new_salary", "New salary (ETB)")}
              error={fieldMessage(t, errors.new_salary?.message)}
            >
              <Input
                {...register("new_salary")}
                type="number"
                step="0.01"
                min="0"
              />
            </Field>

            <Field
              id="action_salary_step"
              label={t("employee.history.field.step", "Salary step")}
              error={fieldMessage(t, errors.salary_step?.message)}
            >
              <Input {...register("salary_step")} type="number" min="1" />
            </Field>
          </div>

          <Field
            id="action_reason"
            label={t("employee.history.field.reason", "Reason / remarks")}
            error={fieldMessage(t, errors.reason?.message)}
          >
            <Textarea {...register("reason")} rows={2} />
          </Field>

          <DialogFooter>
            <Button
              type="button"
              variant="outline"
              onClick={() => onOpenChange(false)}
            >
              {t("common.cancel", "Cancel")}
            </Button>
            <Button type="submit" disabled={isSubmitting}>
              {isSubmitting && (
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
              )}
              {t("employee.history.record", "Record action")}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

function ResourceSelect({
  value,
  onChange,
  options,
  placeholder,
  ...control
}: {
  value: string;
  onChange: (v: string) => void;
  options: Array<{ value: string; label: string }>;
  placeholder: string;
} & Partial<FormControlProps>) {
  const NONE = "__none__";
  return (
    <Select
      value={value === "" ? NONE : value}
      onValueChange={(v) => onChange(v === NONE ? "" : v)}
    >
      {/* The wiring goes on the trigger — that is the focusable element the
          label points at and the one a screen reader announces. */}
      <SelectTrigger {...control}>
        <SelectValue placeholder={placeholder} />
      </SelectTrigger>
      <SelectContent>
        <SelectItem value={NONE}>{placeholder}</SelectItem>
        {options.map((o) => (
          <SelectItem key={o.value} value={o.value}>
            {o.label}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  );
}

/**
 * Every control in the record-action dialog goes through this, so delegating to
 * `FormField` fixes the whole dialog at once: before, the `<Label>` had no
 * `htmlFor` and nothing carried `aria-invalid`, so a rejected field was
 * announced exactly like a valid one.
 *
 * `id` is required rather than optional — a label pointing at nothing is the
 * defect this replaces, and making it optional would let a call site silently
 * reintroduce it.
 */
function Field({
  id,
  label,
  required,
  error,
  children,
}: {
  id: string;
  label: string;
  required?: boolean;
  error?: string;
  children: React.ReactNode | ((control: FormControlProps) => React.ReactNode);
}) {
  return (
    <FormField
      id={id}
      label={<span className="text-xs">{label}</span>}
      required={required}
      error={error}
    >
      {children}
    </FormField>
  );
}
