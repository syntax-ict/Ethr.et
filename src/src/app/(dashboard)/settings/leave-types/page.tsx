"use client";

import { useState } from "react";
import { FileText, Plus, Trash2, Loader2, Pencil } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import { Badge } from "@/components/ui/badge";
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
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from "@/components/ui/dialog";
import { PageHeader } from "@/components/shared/page-header";
import { EmptyState } from "@/components/shared/empty-state";
import { SimpleTable } from "@/components/shared/simple-table";
import { RoleGate } from "@/components/shared/role-gate";
import { FormField } from "@/components/patterns/FormField";
import { FormErrorSummary } from "@/components/patterns/FormErrorSummary";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { Controller } from "react-hook-form";
import { useZodForm } from "@/lib/forms/use-zod-form";
import { rules, fieldMessage } from "@/lib/forms/rules";
import { z } from "zod";
import { apiClient } from "@/api/client";
import { useT } from "@/lib/i18n/useT";
import { statusBadgeClass } from "@/lib/utils/status-colors";
import { toast } from "sonner";

interface LeaveType {
  public_id: string;
  name: string;
  code: string;
  default_days: number;
  accrual_type: string;
  is_active: boolean;
}

const ACCRUAL_TYPES = ["monthly", "annual", "immediate", "one_time"] as const;

const leaveTypeSchema = z.object({
  name: rules.requiredText(255),
  // The code is the stable key balances and payroll join on, so it is worth
  // constraining here rather than letting a stray space or lowercase variant
  // create a second "AL" that reconciles against nothing.
  code: z
    .string()
    .trim()
    .toUpperCase()
    .min(2, "validation.name_min")
    .max(10, "validation.too_long")
    .regex(/^[A-Z0-9_]+$/, "leave_types_page.code_format"),
  // Kept as a string through the form (an empty number input parses to NaN,
  // which reads as "0 days" rather than "you left this blank") and converted
  // once, on submit.
  default_days: rules.integer({ min: 0, max: 365 }),
  accrual_type: z.enum(ACCRUAL_TYPES),
});
type LeaveTypeValues = z.infer<typeof leaveTypeSchema>;

const EMPTY_LEAVE_TYPE: LeaveTypeValues = {
  name: "",
  code: "",
  default_days: "",
  accrual_type: "monthly",
};

export default function LeaveTypesPage() {
  const { t } = useT();
  const queryClient = useQueryClient();
  const [dialogOpen, setDialogOpen] = useState(false);
  const [editingId, setEditingId] = useState<string | null>(null);

  const {
    register,
    control,
    submit,
    reset,
    rootError,
    formState: { errors, isSubmitting },
  } = useZodForm<LeaveTypeValues>({
    schema: leaveTypeSchema,
    defaultValues: EMPTY_LEAVE_TYPE,
  });

  function openNew() {
    setEditingId(null);
    reset(EMPTY_LEAVE_TYPE);
    setDialogOpen(true);
  }

  function openEdit(lt: LeaveType) {
    setEditingId(lt.public_id);
    // `reset` rather than per-field setValue: it also clears errors and the
    // dirty/touched state, so a field rejected on the previous record does not
    // open the dialog already showing a stale message against a valid value.
    reset({
      name: lt.name,
      code: lt.code,
      default_days: String(lt.default_days),
      accrual_type: "monthly",
    });
    setDialogOpen(true);
  }

  const { data, isLoading } = useQuery<{ data: LeaveType[] }>({
    queryKey: ["leave-types"],
    queryFn: async () => {
      const { data } = await apiClient.get("/leave-types");
      return data;
    },
  });

  const createLeaveType = useMutation({
    mutationFn: async (payload: {
      name: string;
      code: string;
      default_days: number;
      accrual_type: string;
    }) => {
      const { data } = await apiClient.post("/leave-types", payload);
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["leave-types"] });
      toast.success(t("leave_types_page.created"));
      setDialogOpen(false);
      reset(EMPTY_LEAVE_TYPE);
    },
    // Errors surface inline in the dialog now — a duplicate `code` is the
    // common rejection here, and it belongs on the code field.
  });

  const updateLeaveType = useMutation({
    mutationFn: async (payload: {
      name: string;
      code: string;
      default_days: number;
      accrual_type: string;
    }) => {
      const { data } = await apiClient.put(
        `/leave-types/${editingId}`,
        payload,
      );
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["leave-types"] });
      toast.success(t("leave_types_page.updated"));
      setDialogOpen(false);
      setEditingId(null);
    },
  });

  const deleteLeaveType = useMutation({
    mutationFn: async (publicId: string) => {
      await apiClient.delete(`/leave-types/${publicId}`);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["leave-types"] });
      toast.success(t("leave_types_page.deleted"));
    },
    onError: () => toast.error(t("leave_types_page.delete_failed")),
  });

  async function onSubmit(values: LeaveTypeValues) {
    const payload = {
      name: values.name,
      code: values.code,
      default_days: Number(values.default_days),
      accrual_type: values.accrual_type,
    };
    await (editingId
      ? updateLeaveType.mutateAsync(payload)
      : createLeaveType.mutateAsync(payload));
  }

  const leaveTypes = data?.data ?? [];

  return (
    <RoleGate minRole="hr_admin">
      <div className="space-y-6">
        <PageHeader
          title={t("leave_types_page.title")}
          description={t("leave_types_page.description")}
          actions={
            <Button onClick={openNew}>
              <Plus className="mr-2 h-4 w-4" />
              {t("leave_types_page.add")}
            </Button>
          }
        />

        {isLoading ? (
          <div className="space-y-3">
            {Array.from({ length: 4 }).map((_, i) => (
              <Skeleton key={i} className="h-12 w-full" />
            ))}
          </div>
        ) : leaveTypes.length === 0 ? (
          <EmptyState
            icon={FileText}
            title={t("leave_types_page.no_leave_types")}
            description={t("leave_types_page.no_leave_types_desc")}
          />
        ) : (
          <Card>
            <CardHeader>
              <CardTitle className="text-base">
                {t("leave_types_page.title")}
              </CardTitle>
            </CardHeader>
            <CardContent className="p-0">
              <SimpleTable
                caption={t("leave_types_page.title")}
                headers={[
                  t("common.name"),
                  t("leave_types_page.code"),
                  t("leave_types_page.default_days"),
                  t("leave_types_page.accrual_type"),
                  t("common.status"),
                ]}
                align={["left", "left", "right", "left", "left"]}
                rows={leaveTypes.map((lt) => ({
                  key: lt.public_id,
                  cells: [
                    <span key="n" className="font-medium">
                      {lt.name}
                    </span>,
                    <span key="c" className="font-mono text-muted-foreground">
                      {lt.code}
                    </span>,
                    <span key="d" className="text-muted-foreground">
                      {lt.default_days}
                    </span>,
                    <span key="a" className="capitalize text-muted-foreground">
                      {lt.accrual_type.replace(/_/g, " ")}
                    </span>,
                    lt.is_active ? (
                      <Badge
                        key="s"
                        variant="outline"
                        className={statusBadgeClass("active")}
                      >
                        {t("webhooks_page.active")}
                      </Badge>
                    ) : (
                      <Badge
                        key="s"
                        variant="outline"
                        className={statusBadgeClass("offline")}
                      >
                        {t("roles_page.inactive")}
                      </Badge>
                    ),
                  ],
                  actions: (
                    <div className="flex justify-end gap-1">
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => openEdit(lt)}
                      >
                        <Pencil className="h-4 w-4" />
                        <span className="sr-only">
                          {t("common.edit", "Edit")}
                        </span>
                      </Button>
                      <Button
                        variant="ghost"
                        size="sm"
                        className="text-destructive-on-soft hover:bg-destructive-soft"
                        onClick={() => deleteLeaveType.mutate(lt.public_id)}
                        disabled={deleteLeaveType.isPending}
                      >
                        <Trash2 className="h-4 w-4" />
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

        <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
          <DialogContent>
            <DialogHeader>
              <DialogTitle>
                {editingId
                  ? t("leave_types_page.edit")
                  : t("leave_types_page.add")}
              </DialogTitle>
            </DialogHeader>
            <form
              onSubmit={submit(
                onSubmit,
                editingId
                  ? t("leave_types_page.update_failed")
                  : t("leave_types_page.create_failed"),
              )}
              className="space-y-4"
              noValidate
            >
              <FormErrorSummary message={rootError} />

              <FormField
                id="lt_name"
                label={t("common.name")}
                required
                error={fieldMessage(t, errors.name?.message)}
              >
                <Input
                  {...register("name")}
                  placeholder={t("leave_types_page.name_placeholder")}
                  className="mt-1"
                />
              </FormField>

              <FormField
                id="lt_code"
                label={t("leave_types_page.code")}
                required
                error={fieldMessage(t, errors.code?.message)}
              >
                <Input
                  {...register("code")}
                  placeholder={t("leave_types_page.code_placeholder")}
                  className="mt-1"
                />
              </FormField>

              <FormField
                id="lt_days"
                label={t("leave_types_page.default_days")}
                required
                error={fieldMessage(t, errors.default_days?.message)}
              >
                <Input
                  {...register("default_days")}
                  type="number"
                  min="0"
                  placeholder={t("leave_types_page.days_placeholder")}
                  className="mt-1"
                />
              </FormField>

              <FormField
                id="lt_accrual"
                label={t("leave_types_page.accrual_type")}
                required
                error={fieldMessage(t, errors.accrual_type?.message)}
              >
                {(control_) => (
                  <Controller
                    name="accrual_type"
                    control={control}
                    render={({ field }) => (
                      <Select
                        value={field.value}
                        onValueChange={field.onChange}
                      >
                        <SelectTrigger {...control_} className="mt-1">
                          <SelectValue
                            placeholder={t(
                              "leave_types_page.select_accrual_type",
                            )}
                          />
                        </SelectTrigger>
                        <SelectContent>
                          <SelectItem value="monthly">
                            {t("leave_types_page.monthly")}
                          </SelectItem>
                          <SelectItem value="annual">
                            {t("leave_types_page.annual")}
                          </SelectItem>
                          <SelectItem value="immediate">
                            {t("leave_types_page.immediate")}
                          </SelectItem>
                          <SelectItem value="one_time">
                            {t("leave_types_page.one_time")}
                          </SelectItem>
                        </SelectContent>
                      </Select>
                    )}
                  />
                )}
              </FormField>

              <DialogFooter>
                <Button
                  type="button"
                  variant="outline"
                  onClick={() => setDialogOpen(false)}
                >
                  {t("common.cancel")}
                </Button>
                <Button type="submit" disabled={isSubmitting}>
                  {isSubmitting && (
                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                  )}
                  {editingId
                    ? t("leave_types_page.save_changes")
                    : t("leave_types_page.add")}
                </Button>
              </DialogFooter>
            </form>
          </DialogContent>
        </Dialog>
      </div>
    </RoleGate>
  );
}
