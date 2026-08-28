"use client";

import { useMemo, useState } from "react";
import { Loader2, Plus } from "lucide-react";
import { z } from "zod";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { SimpleTable } from "@/components/shared/simple-table";
import { EmptyState } from "@/components/shared/empty-state";
import { CurrencyDisplay } from "@/components/shared/currency-display";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import { FormField } from "@/components/patterns/FormField";
import { FormErrorSummary } from "@/components/patterns/FormErrorSummary";
import { useZodForm } from "@/lib/forms/use-zod-form";
import { rules, fieldMessage } from "@/lib/forms/rules";
import { useT } from "@/lib/i18n/useT";
import {
  useGradeSalarySteps,
  useCreateGradeSalaryStep,
  useUpdateGradeSalaryStep,
  useDeleteGradeSalaryStep,
  type Grade,
  type GradeSalaryStep,
} from "@/features/organization/api";
import { Award } from "lucide-react";
import { toast } from "sonner";

interface StepValues {
  step: string;
  salary: string;
}

const EMPTY_STEP: StepValues = { step: "", salary: "" };

export function GradeSalaryStepsDialog({
  grade,
  open,
  onOpenChange,
}: {
  grade: Grade;
  open: boolean;
  onOpenChange: (v: boolean) => void;
}) {
  const { t } = useT();
  const query = useGradeSalarySteps(grade.public_id, open);
  const createStep = useCreateGradeSalaryStep(grade.public_id);
  const updateStep = useUpdateGradeSalaryStep(grade.public_id);
  const deleteStep = useDeleteGradeSalaryStep(grade.public_id);

  const [editing, setEditing] = useState<GradeSalaryStep | null>(null);

  // Bounded by the grade's own band, mirroring the server rule. Monotonicity
  // against neighbouring steps stays server-side — only the API knows the whole
  // ladder — and now arrives inline on the salary field rather than in a toast.
  const stepSchema = useMemo(
    () =>
      z.object({
        step: rules.integer({ min: 1, max: 100 }),
        salary: rules.etb({
          min: grade.min_salary_cents / 100,
          max: grade.max_salary_cents / 100,
        }),
      }),
    [grade.min_salary_cents, grade.max_salary_cents],
  );

  const {
    register,
    submit,
    reset: resetForm,
    rootError,
    formState: { errors, isSubmitting },
  } = useZodForm<StepValues>({
    schema: stepSchema,
    defaultValues: EMPTY_STEP,
  });

  function reset() {
    resetForm(EMPTY_STEP);
    setEditing(null);
  }

  function startEdit(s: GradeSalaryStep) {
    setEditing(s);
    resetForm({
      step: s.step.toString(),
      salary: (s.salary_cents / 100).toString(),
    });
  }

  async function onSubmit(values: StepValues) {
    const input = {
      step: Number(values.step),
      salary_cents: Math.round(Number(values.salary) * 100),
    };

    await (editing
      ? updateStep.mutateAsync({ stepPublicId: editing.public_id, input })
      : createStep.mutateAsync(input));

    toast.success(
      editing
        ? t("org.grade.step.updated", "Step updated")
        : t("org.grade.step.added", "Step added"),
    );
    reset();
  }

  function handleDelete(s: GradeSalaryStep) {
    deleteStep.mutate(s.public_id, {
      onSuccess: () => toast.success(t("org.deleted", "Deleted")),
      onError: () => toast.error(t("org.delete_failed", "Delete failed")),
    });
  }

  return (
    <Dialog
      open={open}
      onOpenChange={(v) => {
        if (!v) reset();
        onOpenChange(v);
      }}
    >
      <DialogContent className="max-w-xl">
        <DialogHeader>
          <DialogTitle>
            {t("org.grade.step.title", "Salary scale")} — {grade.name}
          </DialogTitle>
        </DialogHeader>

        <p className="text-sm text-muted-foreground">
          {t(
            "org.grade.step.range_hint",
            "Each step's salary must fall within the grade band (:min – :max) and rise with the step number.",
            {
              min: (grade.min_salary_cents / 100).toLocaleString(),
              max: (grade.max_salary_cents / 100).toLocaleString(),
            },
          )}
        </p>

        <QueryBoundary
          query={query}
          empty={
            <EmptyState
              icon={Award}
              title={t("org.grade.step.empty_title", "No salary steps yet")}
              description={t(
                "org.grade.step.empty_desc",
                "Add steps to define the pay for each increment within this grade.",
              )}
            />
          }
        >
          {(steps) => (
            <SimpleTable
              headers={[
                t("org.grade.step.step", "Step"),
                t("org.field.salary", "Salary"),
              ]}
              align={["left", "right"]}
              rows={steps.map((s) => ({
                key: s.public_id,
                cells: [
                  s.step,
                  <CurrencyDisplay key="salary" cents={s.salary_cents} />,
                ],
                onEdit: () => startEdit(s),
                onDelete: () => handleDelete(s),
              }))}
            />
          )}
        </QueryBoundary>

        <form
          onSubmit={submit(onSubmit, t("org.save_failed", "Save failed"))}
          className="border-t pt-3"
          noValidate
        >
          <FormErrorSummary message={rootError} className="mb-3" />

          <div className="flex items-start gap-2">
            <FormField
              id="grade_step"
              label={
                <span className="text-xs">
                  {t("org.grade.step.step", "Step")}
                </span>
              }
              required
              error={fieldMessage(t, errors.step?.message)}
              className="w-24"
            >
              <Input
                {...register("step")}
                type="number"
                min="1"
                max="100"
                className="mt-1"
              />
            </FormField>

            <FormField
              id="grade_step_salary"
              label={
                <span className="text-xs">
                  {t("org.field.salary", "Salary")} (ETB)
                </span>
              }
              required
              error={fieldMessage(t, errors.salary?.message)}
              className="flex-1"
            >
              <Input
                {...register("salary")}
                type="number"
                step="0.01"
                min="0"
                className="mt-1"
              />
            </FormField>

            <Button type="submit" className="mt-6" disabled={isSubmitting}>
              {isSubmitting ? (
                <Loader2 className="h-4 w-4 animate-spin" />
              ) : editing ? (
                t("common.save", "Save")
              ) : (
                <>
                  <Plus className="mr-2 h-4 w-4" />
                  {t("org.grade.step.add", "Add step")}
                </>
              )}
            </Button>
          </div>
        </form>

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            {t("common.close", "Close")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
