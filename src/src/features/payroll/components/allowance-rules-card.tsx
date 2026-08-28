"use client";

import { useState } from "react";
import { Coins, Loader2, Pencil, Plus, Trash2 } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Switch } from "@/components/ui/switch";
import { EmptyState } from "@/components/shared/empty-state";
import { SimpleTable } from "@/components/shared/simple-table";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import { FormField } from "@/components/patterns/FormField";
import { FormErrorSummary } from "@/components/patterns/FormErrorSummary";
import { Controller } from "react-hook-form";
import { useZodForm } from "@/lib/forms/use-zod-form";
import { rules as rulesLib, fieldMessage } from "@/lib/forms/rules";
import { z } from "zod";
import { useT } from "@/lib/i18n/useT";
import { etbToCents, formatETB } from "@/lib/utils/currency";
import { statusBadgeClass } from "@/lib/utils/status-colors";
import { toast } from "sonner";
import {
  useAllowanceRules,
  useCreateAllowanceRule,
  useDeleteAllowanceRule,
  useUpdateAllowanceRule,
  type AllowanceRule,
  type AllowanceRuleType,
} from "../api";

interface FormState {
  name: string;
  type: AllowanceRuleType;
  /** ETB for a fixed rule, percent for a percentage rule. */
  value: string;
  is_taxable: boolean;
  is_active: boolean;
  sort_order: string;
}

const EMPTY_FORM: FormState = {
  name: "",
  type: "fixed",
  value: "",
  is_taxable: true,
  is_active: true,
  sort_order: "0",
};

/**
 * `value` means different things depending on `type`, so it is validated
 * against the chosen calculation rather than by one loose numeric rule: a
 * percentage above 100 is nonsense, while a fixed amount above 100 is routine.
 *
 * This replaces a `if (Number.isNaN(numeric)) return;` guard that made Save do
 * nothing at all — no request, no message, no closed dialog — for any
 * unparseable value.
 */
const allowanceSchema = z
  .object({
    name: rulesLib.requiredText(255),
    type: z.enum(["fixed", "percentage"]),
    value: z
      .string()
      .trim()
      .min(1, "validation.required")
      .regex(/^\d+(\.\d{1,2})?$/, "validation.amount"),
    is_taxable: z.boolean(),
    is_active: z.boolean(),
    sort_order: rulesLib.integer({ min: 0, max: 999 }),
  })
  .superRefine((data, ctx) => {
    if (data.type === "percentage" && Number(data.value) > 100) {
      ctx.addIssue({
        code: "custom",
        path: ["value"],
        message: "payroll_config.percent_range",
      });
    }
  });
type AllowanceValues = z.infer<typeof allowanceSchema>;

export function AllowanceRulesCard() {
  const { t } = useT();
  const rules = useAllowanceRules();
  const createRule = useCreateAllowanceRule();
  const updateRule = useUpdateAllowanceRule();
  const deleteRule = useDeleteAllowanceRule();

  const [dialogOpen, setDialogOpen] = useState(false);
  const [editingId, setEditingId] = useState<string | null>(null);

  const {
    register,
    control,
    submit,
    reset,
    watch,
    rootError,
    formState: { errors, isSubmitting },
  } = useZodForm<AllowanceValues>({
    schema: allowanceSchema,
    defaultValues: EMPTY_FORM,
  });

  const type = watch("type");

  function openNew() {
    setEditingId(null);
    reset(EMPTY_FORM);
    setDialogOpen(true);
  }

  function openEdit(rule: AllowanceRule) {
    setEditingId(rule.public_id);
    reset({
      name: rule.name,
      type: rule.type,
      value:
        rule.type === "fixed"
          ? String((rule.formula.amount_cents ?? 0) / 100)
          : String(rule.formula.percent ?? 0),
      is_taxable: rule.is_taxable,
      is_active: rule.is_active,
      sort_order: String(rule.sort_order),
    });
    setDialogOpen(true);
  }

  async function onSubmit(values: AllowanceValues) {
    const numeric = Number(values.value);

    const payload = {
      name: values.name,
      type: values.type,
      formula:
        values.type === "fixed"
          ? { amount_cents: etbToCents(numeric) }
          : { percent: numeric },
      is_taxable: values.is_taxable,
      is_active: values.is_active,
      sort_order: Number(values.sort_order),
    };

    await (editingId
      ? updateRule.mutateAsync({ publicId: editingId, ...payload })
      : createRule.mutateAsync(payload));

    toast.success(
      editingId
        ? t("payroll_config.allowance_updated", "Allowance updated")
        : t("payroll_config.allowance_created", "Allowance created"),
    );
    setDialogOpen(false);
    setEditingId(null);
  }

  function handleDelete(rule: AllowanceRule) {
    deleteRule.mutate(rule.public_id, {
      onSuccess: () =>
        toast.success(
          t("payroll_config.allowance_deleted", "Allowance removed"),
        ),
      onError: () =>
        toast.error(
          t(
            "payroll_config.allowance_delete_failed",
            "Could not remove allowance",
          ),
        ),
    });
  }

  return (
    <Card>
      <CardHeader className="flex flex-row items-start justify-between gap-4">
        <div>
          <CardTitle className="text-base">
            {t("payroll_config.allowances", "Allowances")}
          </CardTitle>
          <p className="mt-1 text-sm text-muted-foreground">
            {t(
              "payroll_config.allowances_desc",
              "Earnings added to gross salary on every payroll run.",
            )}
          </p>
        </div>
        <Button onClick={openNew} size="sm">
          <Plus className="mr-2 h-4 w-4" />
          {t("payroll_config.add_allowance", "Add allowance")}
        </Button>
      </CardHeader>

      <CardContent className="p-0">
        <QueryBoundary
          query={rules}
          empty={
            <EmptyState
              icon={Coins}
              title={t("payroll_config.no_allowances", "No allowances yet")}
              description={t(
                "payroll_config.no_allowances_desc",
                "Add a transport, housing, or position allowance to include it in payroll.",
              )}
            />
          }
        >
          {(data) => (
            <SimpleTable
              caption={t("payroll_config.allowances", "Allowances")}
              headers={[
                t("common.name", "Name"),
                t("payroll_config.amount", "Amount"),
                t("payroll_config.taxable", "Taxable"),
                t("common.status", "Status"),
              ]}
              rows={data.data.map((rule) => ({
                key: rule.public_id,
                cells: [
                  <span key="n" className="font-medium">
                    {rule.name}
                  </span>,
                  <span key="a" className="font-mono text-muted-foreground">
                    {rule.type === "fixed"
                      ? formatETB(rule.formula.amount_cents ?? 0)
                      : `${rule.formula.percent ?? 0}% ${t(
                          "payroll_config.of_basic",
                          "of basic",
                        )}`}
                  </span>,
                  <span key="t" className="text-muted-foreground">
                    {rule.is_taxable
                      ? t("common.yes", "Yes")
                      : t("common.no", "No")}
                  </span>,
                  <Badge
                    key="s"
                    variant="outline"
                    className={statusBadgeClass(
                      rule.is_active ? "active" : "offline",
                    )}
                  >
                    {rule.is_active
                      ? t("common.active", "Active")
                      : t("common.inactive", "Inactive")}
                  </Badge>,
                ],
                actions: (
                  <div className="flex justify-end gap-1">
                    <Button
                      variant="ghost"
                      size="sm"
                      onClick={() => openEdit(rule)}
                      aria-label={t("common.edit", "Edit")}
                    >
                      <Pencil className="h-4 w-4" />
                    </Button>
                    <Button
                      variant="ghost"
                      size="sm"
                      className="text-destructive-on-soft hover:bg-destructive-soft"
                      onClick={() => handleDelete(rule)}
                      disabled={deleteRule.isPending}
                      aria-label={t("common.delete", "Delete")}
                    >
                      <Trash2 className="h-4 w-4" />
                    </Button>
                  </div>
                ),
              }))}
            />
          )}
        </QueryBoundary>
      </CardContent>

      <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>
              {editingId
                ? t("payroll_config.edit_allowance", "Edit allowance")
                : t("payroll_config.add_allowance", "Add allowance")}
            </DialogTitle>
          </DialogHeader>

          <form
            onSubmit={submit(
              onSubmit,
              t(
                "payroll_config.allowance_save_failed",
                "Could not save allowance",
              ),
            )}
            className="space-y-4"
            noValidate
          >
            <FormErrorSummary message={rootError} />

            <FormField
              id="allowance_name"
              label={t("common.name", "Name")}
              required
              error={fieldMessage(t, errors.name?.message)}
            >
              <Input
                {...register("name")}
                placeholder={t(
                  "payroll_config.allowance_name_placeholder",
                  "Transport Allowance",
                )}
                className="mt-1"
              />
            </FormField>

            <FormField
              id="allowance_type"
              label={t("payroll_config.calculation", "Calculation")}
              required
            >
              {(control_) => (
                <Controller
                  name="type"
                  control={control}
                  render={({ field }) => (
                    <Select value={field.value} onValueChange={field.onChange}>
                      <SelectTrigger {...control_} className="mt-1">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="fixed">
                          {t("payroll_config.fixed_amount", "Fixed amount")}
                        </SelectItem>
                        <SelectItem value="percentage">
                          {t(
                            "payroll_config.percent_of_basic",
                            "Percentage of basic salary",
                          )}
                        </SelectItem>
                      </SelectContent>
                    </Select>
                  )}
                />
              )}
            </FormField>

            <FormField
              id="allowance_value"
              label={
                type === "fixed"
                  ? t("payroll_config.amount_etb", "Amount (ETB)")
                  : t("payroll_config.percent", "Percent")
              }
              required
              error={fieldMessage(t, errors.value?.message)}
            >
              <Input
                {...register("value")}
                type="number"
                min="0"
                max={type === "percentage" ? "100" : undefined}
                step="0.01"
                className="mt-1"
              />
            </FormField>

            <div className="flex items-center justify-between rounded-md border p-3">
              <div>
                <Label htmlFor="allowance_taxable">
                  {t("payroll_config.taxable", "Taxable")}
                </Label>
                <p className="text-xs text-muted-foreground">
                  {t(
                    "payroll_config.taxable_hint",
                    "Non-taxable allowances are excluded from taxable income.",
                  )}
                </p>
              </div>
              <Controller
                name="is_taxable"
                control={control}
                render={({ field }) => (
                  <Switch
                    id="allowance_taxable"
                    checked={field.value}
                    onCheckedChange={field.onChange}
                  />
                )}
              />
            </div>

            <div className="flex items-center justify-between rounded-md border p-3">
              <Label htmlFor="allowance_active">
                {t("common.active", "Active")}
              </Label>
              <Controller
                name="is_active"
                control={control}
                render={({ field }) => (
                  <Switch
                    id="allowance_active"
                    checked={field.value}
                    onCheckedChange={field.onChange}
                  />
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
                {t("common.save", "Save")}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </Card>
  );
}
