"use client";

import { useMemo, useState } from "react";
import { Controller, useFieldArray } from "react-hook-form";
import { z } from "zod";
import {
  ArrowDown,
  ArrowUp,
  GitBranch,
  Loader2,
  Pencil,
  Plus,
  Trash2,
} from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Switch } from "@/components/ui/switch";
import { EmptyState } from "@/components/shared/empty-state";
import { PageActions } from "@/components/shared/page-actions";
import { RoleGate } from "@/components/shared/role-gate";
import { useConfirm } from "@/components/shared/use-confirm";
import { FormErrorSummary } from "@/components/patterns/FormErrorSummary";
import { FormField } from "@/components/patterns/FormField";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import {
  useApprovalWorkflows,
  useApproverUsers,
  useDeleteApprovalWorkflow,
  useSaveApprovalWorkflow,
  type ApprovalRequestType,
  type ApprovalWorkflow,
  type ApprovalWorkflowPayload,
  type ApproverKind,
} from "@/features/approvals/workflows";
import { useAllLeaveTypes } from "@/features/leave/api";
import { branchesApi, departmentsApi } from "@/features/organization/api";
import { apiErrorMessage } from "@/lib/api/error-message";
import { fieldMessage, rules } from "@/lib/forms/rules";
import { useZodForm } from "@/lib/forms/use-zod-form";
import { useT } from "@/lib/i18n/useT";

const REQUEST_TYPES = [
  "leave",
  "correction",
] as const satisfies readonly ApprovalRequestType[];

const KINDS = [
  "supervisor",
  "department_head",
  "role",
  "user",
  "permission",
] as const satisfies readonly ApproverKind[];

/** The roles a step may name: the server refuses `employee` and `super_admin`. */
const ROLES = [
  "hr_admin",
  "tenant_admin",
  "finance_admin",
  "dept_admin",
  "supervisor",
] as const;

const MAX_STEPS = 5;

const stepSchema = z
  .object({
    approver_kind: z.enum(KINDS),
    approver_ref: z.string(),
  })
  .refine(
    (s) =>
      !(s.approver_kind === "role" || s.approver_kind === "user") ||
      s.approver_ref !== "",
    { path: ["approver_ref"], message: "approval_chains.ref_required" },
  );

const chainSchema = z.object({
  name: rules.requiredText(120),
  request_type: z.enum(REQUEST_TYPES),
  is_active: z.boolean(),
  priority: rules.integer({ min: 0, max: 1000 }),
  department_ids: z.array(z.string()),
  branch_ids: z.array(z.string()),
  leave_type_ids: z.array(z.string()),
  steps: z.array(stepSchema).min(1).max(MAX_STEPS),
});
type ChainValues = z.infer<typeof chainSchema>;

const EMPTY_CHAIN: ChainValues = {
  name: "",
  request_type: "leave",
  is_active: true,
  priority: "0",
  department_ids: [],
  branch_ids: [],
  leave_type_ids: [],
  steps: [
    { approver_kind: "supervisor", approver_ref: "" },
    { approver_kind: "role", approver_ref: "hr_admin" },
  ],
};

const FORM_FIELDS = [
  "name",
  "request_type",
  "is_active",
  "priority",
  "steps",
  ...Array.from(
    { length: MAX_STEPS },
    (_, i) => `steps.${i}.approver_ref` as const,
  ),
] as const;

function isKind(value: string): value is ApproverKind {
  return (KINDS as readonly string[]).includes(value);
}

function toValues(w: ApprovalWorkflow): ChainValues {
  return {
    name: w.name,
    request_type: w.request_type === "correction" ? "correction" : "leave",
    is_active: w.is_active,
    priority: String(w.priority),
    department_ids: w.conditions?.department_ids ?? [],
    branch_ids: w.conditions?.branch_ids ?? [],
    leave_type_ids: w.conditions?.leave_type_ids ?? [],
    steps: (w.steps ?? []).map((s) => ({
      approver_kind: isKind(s.approver_kind) ? s.approver_kind : "permission",
      approver_ref: s.approver_ref ?? "",
    })),
  };
}

function toPayload(v: ChainValues): ApprovalWorkflowPayload {
  return {
    name: v.name,
    request_type: v.request_type,
    is_active: v.is_active,
    priority: Number(v.priority),
    conditions: {
      department_ids: v.department_ids,
      branch_ids: v.branch_ids,
      leave_type_ids: v.request_type === "leave" ? v.leave_type_ids : [],
    },
    steps: v.steps.map((s) => ({
      approver_kind: s.approver_kind,
      approver_ref:
        s.approver_kind === "role" || s.approver_kind === "user"
          ? s.approver_ref
          : null,
    })),
  };
}

interface Option {
  value: string;
  label: string;
}

/** A short list of ticks; nothing ticked means "every one". */
function CheckList({
  id,
  options,
  value,
  onChange,
  emptyText,
}: {
  id: string;
  options: Option[];
  value: string[];
  onChange: (next: string[]) => void;
  emptyText: string;
}) {
  if (options.length === 0) {
    return <p className="text-sm text-muted-foreground">{emptyText}</p>;
  }

  return (
    <div className="max-h-40 space-y-2 overflow-y-auto rounded-lg border p-3">
      {options.map((o) => {
        const checkboxId = `${id}-${o.value}`;
        const checked = value.includes(o.value);
        return (
          <label
            key={o.value}
            htmlFor={checkboxId}
            className="flex items-center gap-2 text-sm"
          >
            <Checkbox
              id={checkboxId}
              checked={checked}
              onCheckedChange={(next) =>
                onChange(
                  next === true
                    ? [...value, o.value]
                    : value.filter((v) => v !== o.value),
                )
              }
            />
            {o.label}
          </label>
        );
      })}
    </div>
  );
}

export default function ApprovalChainsPage() {
  const { t } = useT();
  const workflows = useApprovalWorkflows();
  const save = useSaveApprovalWorkflow();
  const remove = useDeleteApprovalWorkflow();
  const { requestConfirm, confirmDialog } = useConfirm();

  const [dialogOpen, setDialogOpen] = useState(false);
  const [editingId, setEditingId] = useState<string | null>(null);

  const departments = departmentsApi.useList();
  const branches = branchesApi.useList();
  const leaveTypes = useAllLeaveTypes();

  const namesUser = (workflows.data ?? []).some((w) =>
    (w.steps ?? []).some((s) => s.approver_kind === "user"),
  );
  const users = useApproverUsers(dialogOpen || namesUser);

  const {
    register,
    control,
    submit,
    reset,
    watch,
    rootError,
    formState: { errors, isSubmitting },
  } = useZodForm<ChainValues>({
    schema: chainSchema,
    defaultValues: EMPTY_CHAIN,
    fields: FORM_FIELDS,
  });
  const steps = useFieldArray({ control, name: "steps" });
  const requestType = watch("request_type");

  const departmentOptions: Option[] = useMemo(
    () =>
      (departments.data?.data ?? []).map((d) => ({
        value: d.public_id,
        label: d.name,
      })),
    [departments.data],
  );
  const branchOptions: Option[] = useMemo(
    () =>
      (branches.data?.data ?? []).map((b) => ({
        value: b.public_id,
        label: b.name,
      })),
    [branches.data],
  );
  const leaveTypeOptions: Option[] = useMemo(
    () =>
      (leaveTypes.data ?? []).map((lt) => ({
        value: lt.public_id,
        label: lt.name,
      })),
    [leaveTypes.data],
  );
  const userOptions: Option[] = useMemo(
    () =>
      (users.data ?? []).map((u) => ({
        value: u.public_id,
        label: u.employee?.name ?? u.email,
      })),
    [users.data],
  );

  const typeLabel = (type: string): string =>
    type === "correction"
      ? t("approval_chains.type_correction", "Attendance corrections")
      : t("approval_chains.type_leave", "Leave requests");

  const kindLabel = (kind: string): string => {
    switch (kind) {
      case "supervisor":
        return t("approval_chains.kind_supervisor", "Their supervisor");
      case "department_head":
        return t(
          "approval_chains.kind_department_head",
          "Their department head",
        );
      case "role":
        return t("approval_chains.kind_role", "Anyone with a role");
      case "user":
        return t("approval_chains.kind_user", "One named person");
      default:
        return t(
          "approval_chains.kind_permission",
          "Anyone who may approve it",
        );
    }
  };

  const roleLabel = (role: string): string => t(`role.${role}`, role);

  const stepLabel = (kind: string, ref: string | null | undefined): string => {
    if (kind === "role" && ref) return `${kindLabel(kind)}: ${roleLabel(ref)}`;
    if (kind === "user" && ref) {
      const name = userOptions.find((o) => o.value === ref)?.label;
      return name ?? kindLabel(kind);
    }
    return kindLabel(kind);
  };

  const conditionSummary = (w: ApprovalWorkflow): string => {
    const named = (ids: string[] | undefined, options: Option[]) =>
      (ids ?? [])
        .map((id) => options.find((o) => o.value === id)?.label)
        .filter((l): l is string => Boolean(l))
        .join(", ");

    const parts = [
      named(w.conditions?.department_ids, departmentOptions),
      named(w.conditions?.branch_ids, branchOptions),
      named(w.conditions?.leave_type_ids, leaveTypeOptions),
    ].filter((p) => p !== "");

    return parts.length === 0
      ? t(
          "approval_chains.applies_all",
          "Applies to every request of this type",
        )
      : t("approval_chains.applies_some", "Applies to: :list", {
          list: parts.join("; "),
        });
  };

  function openNew() {
    setEditingId(null);
    reset(EMPTY_CHAIN);
    setDialogOpen(true);
  }

  function openEdit(w: ApprovalWorkflow) {
    setEditingId(w.public_id);
    reset(toValues(w));
    setDialogOpen(true);
  }

  async function onSubmit(values: ChainValues) {
    await save.mutateAsync({
      publicId: editingId,
      payload: toPayload(values),
    });
    toast.success(
      editingId
        ? t("approval_chains.saved", "Approval chain saved")
        : t("approval_chains.created", "Approval chain created"),
    );
    setDialogOpen(false);
  }

  async function confirmDelete(w: ApprovalWorkflow) {
    const ok = await requestConfirm({
      title: t("approval_chains.delete_title", "Delete the chain :name?", {
        name: w.name,
      }),
      description: t(
        "approval_chains.delete_desc",
        "Requests already submitted keep the steps they started with. New requests are decided in one step unless another chain applies.",
      ),
      confirmLabel: t("common.delete", "Delete"),
      variant: "destructive",
    });
    if (!ok) return;

    remove.mutate(w.public_id, {
      onSuccess: () =>
        toast.success(t("approval_chains.deleted", "Approval chain deleted")),
      onError: (err) =>
        toast.error(
          apiErrorMessage(
            err,
            t(
              "approval_chains.delete_failed",
              "The approval chain could not be deleted.",
            ),
          ),
        ),
    });
  }

  return (
    <RoleGate anyPermission={["manageSettings"]}>
      <div className="space-y-6">
        <PageActions
          actions={
            <Button onClick={openNew}>
              <Plus className="mr-2 h-4 w-4" />
              {t("approval_chains.add", "New chain")}
            </Button>
          }
        />

        <Card>
          <CardHeader>
            <CardTitle className="text-base">
              {t("approval_chains.how_title", "How approval chains work")}
            </CardTitle>
          </CardHeader>
          <CardContent className="space-y-2 text-sm text-muted-foreground">
            <p>
              {t(
                "approval_chains.how_default",
                "Without a chain, a leave request or attendance correction is decided in one step by anyone who may approve it for that employee.",
              )}
            </p>
            <p>
              {t(
                "approval_chains.how_steps",
                "A chain sends it through up to five steps in order. Each step still needs the approve permission and access to the employee, and nobody approves their own request or two steps of the same one. A step that names no one who can decide passes to HR, and the request's history says so.",
              )}
            </p>
            <p>
              {t(
                "approval_chains.how_changes",
                "Changes apply to requests submitted afterwards. Requests already waiting keep the steps they started with.",
              )}
            </p>
          </CardContent>
        </Card>

        <QueryBoundary
          query={workflows}
          empty={
            <EmptyState
              icon={GitBranch}
              title={t("approval_chains.empty_title", "No approval chains")}
              description={t(
                "approval_chains.empty_desc",
                "Every request is decided in one step. Add a chain to require more than one approver.",
              )}
              action={
                <Button onClick={openNew}>
                  <Plus className="mr-2 h-4 w-4" />
                  {t("approval_chains.add", "New chain")}
                </Button>
              }
            />
          }
        >
          {(list) => (
            <div className="space-y-6">
              {REQUEST_TYPES.map((type) => {
                const chains = list.filter((w) => w.request_type === type);
                if (chains.length === 0) return null;

                return (
                  <section key={type} className="space-y-3">
                    <h2 className="text-sm font-semibold text-foreground">
                      {typeLabel(type)}
                    </h2>
                    {chains.map((w) => (
                      <Card key={w.public_id}>
                        <CardContent className="space-y-3 pt-6">
                          <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div className="space-y-1">
                              <div className="flex flex-wrap items-center gap-2">
                                <p className="font-medium text-foreground">
                                  {w.name}
                                </p>
                                <Badge
                                  variant={w.is_active ? "success" : "neutral"}
                                >
                                  {w.is_active
                                    ? t("approval_chains.active", "Active")
                                    : t("approval_chains.inactive", "Off")}
                                </Badge>
                              </div>
                              <p className="text-xs text-muted-foreground">
                                {conditionSummary(w)}
                              </p>
                            </div>
                            <div className="flex shrink-0 gap-2">
                              <Button
                                variant="outline"
                                size="sm"
                                onClick={() => openEdit(w)}
                              >
                                <Pencil className="mr-2 h-4 w-4" />
                                {t("common.edit", "Edit")}
                              </Button>
                              <Button
                                variant="outline"
                                size="sm"
                                onClick={() => confirmDelete(w)}
                                aria-label={t(
                                  "approval_chains.delete_named",
                                  "Delete :name",
                                  { name: w.name },
                                )}
                              >
                                <Trash2 className="h-4 w-4" />
                              </Button>
                            </div>
                          </div>
                          <ol className="space-y-1 text-sm">
                            {(w.steps ?? []).map((s) => (
                              <li
                                key={s.position}
                                className="flex items-center gap-2"
                              >
                                <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary-soft text-caption font-semibold text-primary-on-soft">
                                  {s.position}
                                </span>
                                {stepLabel(s.approver_kind, s.approver_ref)}
                              </li>
                            ))}
                          </ol>
                        </CardContent>
                      </Card>
                    ))}
                  </section>
                );
              })}
            </div>
          )}
        </QueryBoundary>

        <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
          <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
            <DialogHeader>
              <DialogTitle>
                {editingId
                  ? t("approval_chains.edit", "Edit approval chain")
                  : t("approval_chains.add", "New chain")}
              </DialogTitle>
            </DialogHeader>
            <form
              onSubmit={submit(
                onSubmit,
                t(
                  "approval_chains.save_failed",
                  "The approval chain could not be saved.",
                ),
              )}
              className="space-y-5"
              noValidate
            >
              <FormErrorSummary message={rootError} />

              <div className="grid gap-4 sm:grid-cols-2">
                <FormField
                  id="chain_name"
                  label={t("common.name", "Name")}
                  required
                  error={fieldMessage(t, errors.name?.message)}
                >
                  <Input
                    {...register("name")}
                    placeholder={t(
                      "approval_chains.name_placeholder",
                      "e.g. Supervisor, then HR",
                    )}
                    className="mt-1"
                  />
                </FormField>

                <Controller
                  name="request_type"
                  control={control}
                  render={({ field }) => (
                    <FormField
                      id="chain_type"
                      label={t("approval_chains.request_type", "Applies to")}
                      required
                    >
                      {(controlProps) => (
                        <Select
                          value={field.value}
                          onValueChange={field.onChange}
                        >
                          <SelectTrigger {...controlProps} className="mt-1">
                            <SelectValue />
                          </SelectTrigger>
                          <SelectContent>
                            {REQUEST_TYPES.map((type) => (
                              <SelectItem key={type} value={type}>
                                {typeLabel(type)}
                              </SelectItem>
                            ))}
                          </SelectContent>
                        </Select>
                      )}
                    </FormField>
                  )}
                />
              </div>

              <fieldset className="space-y-3">
                <legend className="text-sm font-medium text-foreground">
                  {t("approval_chains.steps", "Steps, in order")}
                </legend>
                {errors.steps?.message && (
                  <p className="text-sm text-destructive">
                    {fieldMessage(t, errors.steps.message)}
                  </p>
                )}
                <ol className="space-y-3">
                  {steps.fields.map((field, index) => (
                    <li
                      key={field.id}
                      className="flex flex-col gap-2 rounded-lg border p-3 sm:flex-row sm:items-start"
                    >
                      <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary-soft text-caption font-semibold text-primary-on-soft sm:mt-2">
                        {index + 1}
                      </span>
                      <div className="grid flex-1 gap-2 sm:grid-cols-2">
                        <Controller
                          name={`steps.${index}.approver_kind`}
                          control={control}
                          render={({ field: kindField }) => (
                            <Select
                              value={kindField.value}
                              onValueChange={(value) => {
                                // The reference belongs to the kind, so a new
                                // kind starts from its own default.
                                steps.update(index, {
                                  approver_kind: isKind(value)
                                    ? value
                                    : "permission",
                                  approver_ref:
                                    value === "role" ? "hr_admin" : "",
                                });
                              }}
                            >
                              <SelectTrigger
                                aria-label={t(
                                  "approval_chains.step_kind",
                                  "Who decides step :n",
                                  { n: index + 1 },
                                )}
                              >
                                <SelectValue />
                              </SelectTrigger>
                              <SelectContent>
                                {KINDS.map((kind) => (
                                  <SelectItem key={kind} value={kind}>
                                    {kindLabel(kind)}
                                  </SelectItem>
                                ))}
                              </SelectContent>
                            </Select>
                          )}
                        />
                        <Controller
                          name={`steps.${index}.approver_ref`}
                          control={control}
                          render={({ field: refField }) => {
                            const kind = watch(`steps.${index}.approver_kind`);
                            const refError = fieldMessage(
                              t,
                              errors.steps?.[index]?.approver_ref?.message,
                            );
                            if (kind !== "role" && kind !== "user") {
                              return <span />;
                            }
                            const options =
                              kind === "role"
                                ? ROLES.map((r) => ({
                                    value: r,
                                    label: roleLabel(r),
                                  }))
                                : userOptions;
                            return (
                              <div>
                                <Select
                                  value={refField.value}
                                  onValueChange={refField.onChange}
                                >
                                  <SelectTrigger
                                    aria-label={
                                      kind === "role"
                                        ? t(
                                            "approval_chains.step_role",
                                            "Role for step :n",
                                            { n: index + 1 },
                                          )
                                        : t(
                                            "approval_chains.step_user",
                                            "Person for step :n",
                                            { n: index + 1 },
                                          )
                                    }
                                    aria-invalid={refError ? true : undefined}
                                  >
                                    <SelectValue
                                      placeholder={t(
                                        "approval_chains.choose",
                                        "Choose",
                                      )}
                                    />
                                  </SelectTrigger>
                                  <SelectContent>
                                    {options.map((o) => (
                                      <SelectItem key={o.value} value={o.value}>
                                        {o.label}
                                      </SelectItem>
                                    ))}
                                  </SelectContent>
                                </Select>
                                {refError && (
                                  <p className="mt-1 text-sm text-destructive">
                                    {refError}
                                  </p>
                                )}
                              </div>
                            );
                          }}
                        />
                      </div>
                      <div className="flex gap-1">
                        <Button
                          type="button"
                          variant="ghost"
                          size="icon"
                          disabled={index === 0}
                          onClick={() => steps.move(index, index - 1)}
                          aria-label={t(
                            "approval_chains.move_up",
                            "Move step :n up",
                            { n: index + 1 },
                          )}
                        >
                          <ArrowUp className="h-4 w-4" />
                        </Button>
                        <Button
                          type="button"
                          variant="ghost"
                          size="icon"
                          disabled={index === steps.fields.length - 1}
                          onClick={() => steps.move(index, index + 1)}
                          aria-label={t(
                            "approval_chains.move_down",
                            "Move step :n down",
                            { n: index + 1 },
                          )}
                        >
                          <ArrowDown className="h-4 w-4" />
                        </Button>
                        <Button
                          type="button"
                          variant="ghost"
                          size="icon"
                          disabled={steps.fields.length === 1}
                          onClick={() => steps.remove(index)}
                          aria-label={t(
                            "approval_chains.remove_step",
                            "Remove step :n",
                            { n: index + 1 },
                          )}
                        >
                          <Trash2 className="h-4 w-4" />
                        </Button>
                      </div>
                    </li>
                  ))}
                </ol>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  disabled={steps.fields.length >= MAX_STEPS}
                  onClick={() =>
                    steps.append({
                      approver_kind: "permission",
                      approver_ref: "",
                    })
                  }
                >
                  <Plus className="mr-2 h-4 w-4" />
                  {t("approval_chains.add_step", "Add a step")}
                </Button>
              </fieldset>

              <fieldset className="space-y-3">
                <legend className="text-sm font-medium text-foreground">
                  {t("approval_chains.conditions", "Only for")}
                </legend>
                <p className="text-xs text-muted-foreground">
                  {t(
                    "approval_chains.conditions_hint",
                    "Leave everything unticked to cover every request of this type.",
                  )}
                </p>
                <div className="grid gap-4 sm:grid-cols-2">
                  <Controller
                    name="department_ids"
                    control={control}
                    render={({ field }) => (
                      <div className="space-y-1">
                        <p className="text-sm">
                          {t("approval_chains.departments", "Departments")}
                        </p>
                        <CheckList
                          id="chain_departments"
                          options={departmentOptions}
                          value={field.value}
                          onChange={field.onChange}
                          emptyText={t(
                            "approval_chains.none_set_up",
                            "None set up yet.",
                          )}
                        />
                      </div>
                    )}
                  />
                  <Controller
                    name="branch_ids"
                    control={control}
                    render={({ field }) => (
                      <div className="space-y-1">
                        <p className="text-sm">
                          {t("approval_chains.branches", "Branches")}
                        </p>
                        <CheckList
                          id="chain_branches"
                          options={branchOptions}
                          value={field.value}
                          onChange={field.onChange}
                          emptyText={t(
                            "approval_chains.none_set_up",
                            "None set up yet.",
                          )}
                        />
                      </div>
                    )}
                  />
                  {requestType === "leave" && (
                    <Controller
                      name="leave_type_ids"
                      control={control}
                      render={({ field }) => (
                        <div className="space-y-1">
                          <p className="text-sm">
                            {t("approval_chains.leave_types", "Leave types")}
                          </p>
                          <CheckList
                            id="chain_leave_types"
                            options={leaveTypeOptions}
                            value={field.value}
                            onChange={field.onChange}
                            emptyText={t(
                              "approval_chains.none_set_up",
                              "None set up yet.",
                            )}
                          />
                        </div>
                      )}
                    />
                  )}
                </div>
              </fieldset>

              <div className="grid gap-4 sm:grid-cols-2">
                <FormField
                  id="chain_priority"
                  label={t("approval_chains.priority", "Priority")}
                  hint={t(
                    "approval_chains.priority_hint",
                    "When two chains match a request, the higher number wins.",
                  )}
                  error={fieldMessage(t, errors.priority?.message)}
                >
                  <Input
                    {...register("priority")}
                    type="number"
                    min="0"
                    className="mt-1"
                  />
                </FormField>
                <Controller
                  name="is_active"
                  control={control}
                  render={({ field }) => (
                    <label className="flex items-center justify-between gap-3 self-end rounded-lg border p-3 text-sm">
                      <span>
                        {t("approval_chains.is_active", "Use this chain")}
                      </span>
                      <Switch
                        checked={field.value}
                        onCheckedChange={field.onChange}
                        aria-label={t(
                          "approval_chains.is_active",
                          "Use this chain",
                        )}
                      />
                    </label>
                  )}
                />
              </div>

              <DialogFooter>
                <Button
                  type="button"
                  variant="outline"
                  onClick={() => setDialogOpen(false)}
                >
                  {t("common.cancel", "Cancel")}
                </Button>
                <Button type="submit" disabled={isSubmitting}>
                  {isSubmitting && (
                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                  )}
                  {editingId
                    ? t("approval_chains.save", "Save chain")
                    : t("approval_chains.create", "Create chain")}
                </Button>
              </DialogFooter>
            </form>
          </DialogContent>
        </Dialog>

        {confirmDialog}
      </div>
    </RoleGate>
  );
}
