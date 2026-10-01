"use client";

import { useState } from "react";
import { Loader2, Plus, Trash2, GraduationCap } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from "@/components/ui/dialog";
import { EmptyState } from "@/components/shared/empty-state";
import { FormField } from "@/components/patterns/FormField";
import { FormErrorSummary } from "@/components/patterns/FormErrorSummary";
import { useZodForm } from "@/lib/forms/use-zod-form";
import { rules, fieldMessage } from "@/lib/forms/rules";
import { z } from "zod";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";
import { toastError } from "@/lib/errors";
import {
  useAddEducation,
  useDeleteEducation,
  useEmployeeEducation,
} from "../api";

/**
 * The form asks for years, because that is how people remember a degree, but
 * StoreEducationRequest takes `start_date`, `end_date` and `grade`. Sending
 * `start_year`, `end_year` and `gpa` — as this form did — had all three
 * dropped by `validated()`: every record was saved with no dates and no
 * grade. A year is stored as 1 January of it and only the year is shown.
 */
function yearToDate(year: string): string | undefined {
  return year === "" ? undefined : `${year}-01-01`;
}

function dateToYear(date: string | null): string | null {
  return date ? date.slice(0, 4) : null;
}

const CURRENT_YEAR = new Date().getFullYear();

const optionalYear = z
  .string()
  .trim()
  .refine(
    (v) =>
      v === "" ||
      (/^\d{4}$/.test(v) &&
        Number(v) >= 1900 &&
        // A year in the future is a typo, not a plan — an end year *can* be
        // next year for someone still studying, hence the +10 rather than a
        // hard stop at today.
        Number(v) <= CURRENT_YEAR + 10),
    "employee.education.year_invalid",
  );

const educationSchema = z
  .object({
    institution: rules.requiredText(255),
    degree: rules.requiredText(255),
    field_of_study: rules.text(255),
    start_year: optionalYear,
    end_year: optionalYear,
    // Ethiopian universities grade on a 4.0 scale.
    gpa: z
      .string()
      .trim()
      .refine(
        (v) => v === "" || (/^\d(\.\d{1,2})?$/.test(v) && Number(v) <= 4),
        "employee.education.gpa_invalid",
      ),
  })
  .superRefine((data, ctx) => {
    if (
      data.start_year !== "" &&
      data.end_year !== "" &&
      Number(data.end_year) < Number(data.start_year)
    ) {
      ctx.addIssue({
        code: "custom",
        path: ["end_year"],
        message: "employee.education.year_order",
      });
    }
  });
type EducationValues = z.infer<typeof educationSchema>;

const EMPTY_EDUCATION: EducationValues = {
  institution: "",
  degree: "",
  field_of_study: "",
  start_year: "",
  end_year: "",
  gpa: "",
};

export function EducationTab({ employeeId }: { employeeId: string }) {
  const { t } = useT();
  const [addOpen, setAddOpen] = useState(false);

  const {
    register,
    submit,
    reset,
    rootError,
    formState: { errors, isSubmitting },
  } = useZodForm<EducationValues>({
    schema: educationSchema,
    defaultValues: EMPTY_EDUCATION,
  });

  const { data, isLoading } = useEmployeeEducation(employeeId);
  const addEducation = useAddEducation(employeeId);
  const deleteEducation = useDeleteEducation(employeeId);

  async function onAdd(values: EducationValues) {
    await addEducation.mutateAsync({
      institution: values.institution,
      degree: values.degree,
      field_of_study: values.field_of_study,
      start_date: yearToDate(values.start_year),
      end_date: yearToDate(values.end_year),
      grade: values.gpa === "" ? undefined : values.gpa,
    });
    toast.success(t("employee.education.added", "Education added"));
    setAddOpen(false);
    reset(EMPTY_EDUCATION);
  }

  function onDelete(id: string) {
    deleteEducation.mutate(id, {
      onSuccess: () =>
        toast.success(t("employee.education.deleted", "Education deleted")),
      onError: (error) =>
        toastError(
          error,
          t(
            "employee.education.delete_failed",
            "Could not delete the education record",
          ),
        ),
    });
  }

  const records = data ?? [];

  return (
    <Card>
      <CardHeader className="flex flex-row items-center justify-between">
        <CardTitle className="text-base">
          {t("employee.education.title", "Education History")}
        </CardTitle>
        <Button size="sm" onClick={() => setAddOpen(true)}>
          <Plus className="mr-2 h-3 w-3" /> {t("common.add", "Add")}
        </Button>
      </CardHeader>
      <CardContent>
        {isLoading ? (
          <Skeleton className="h-20 w-full" />
        ) : records.length === 0 ? (
          <EmptyState
            icon={GraduationCap}
            title={t("employee.education.empty_title", "No education records")}
            description={t(
              "employee.education.empty_desc",
              "Add educational qualifications",
            )}
          />
        ) : (
          <div className="space-y-2">
            {records.map((e) => (
              <div
                key={e.public_id}
                className="flex items-start justify-between rounded-lg border p-3"
              >
                <div className="flex items-start gap-3">
                  <GraduationCap className="mt-0.5 h-4 w-4 text-muted-foreground" />
                  <div>
                    <p className="text-sm font-medium">
                      {e.degree}
                      {e.field_of_study && ` — ${e.field_of_study}`}
                    </p>
                    <p className="text-xs text-muted-foreground">
                      {e.institution}
                    </p>
                    {(e.start_date || e.end_date) && (
                      <p className="text-xs text-muted-foreground">
                        {dateToYear(e.start_date) ?? ""} –{" "}
                        {dateToYear(e.end_date) ??
                          t("employee.education.present", "Present")}
                      </p>
                    )}
                    {e.grade && (
                      <p className="text-xs text-muted-foreground">
                        {t("employee.education.gpa", "GPA")}: {e.grade}
                      </p>
                    )}
                  </div>
                </div>
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => onDelete(e.public_id)}
                  aria-label={`${t("common.delete", "Delete")} ${e.degree}`}
                >
                  <Trash2 className="h-4 w-4 text-destructive" />
                </Button>
              </div>
            ))}
          </div>
        )}
      </CardContent>

      <Dialog open={addOpen} onOpenChange={setAddOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>
              {t("employee.education.add_title", "Add Education")}
            </DialogTitle>
          </DialogHeader>
          <form
            onSubmit={submit(
              onAdd,
              t("employee.education.add_failed", "Failed to add education"),
            )}
            className="space-y-4"
            noValidate
          >
            <FormErrorSummary message={rootError} />

            <FormField
              id="edu_institution"
              label={t("employee.education.institution", "Institution")}
              required
              error={fieldMessage(t, errors.institution?.message)}
            >
              <Input {...register("institution")} className="mt-1" />
            </FormField>

            <FormField
              id="edu_degree"
              label={t("employee.education.degree", "Degree")}
              required
              error={fieldMessage(t, errors.degree?.message)}
            >
              <Input
                {...register("degree")}
                placeholder="BSc, MSc, MBA..."
                className="mt-1"
              />
            </FormField>

            <FormField
              id="edu_field"
              label={t("employee.education.field_of_study", "Field of Study")}
              error={fieldMessage(t, errors.field_of_study?.message)}
            >
              <Input
                {...register("field_of_study")}
                placeholder="Computer Science..."
                className="mt-1"
              />
            </FormField>

            <div className="grid grid-cols-3 gap-3">
              <FormField
                id="edu_start_year"
                label={t("employee.education.start_year", "Start Year")}
                error={fieldMessage(t, errors.start_year?.message)}
              >
                <Input
                  {...register("start_year")}
                  inputMode="numeric"
                  placeholder="2015"
                  className="mt-1"
                />
              </FormField>

              <FormField
                id="edu_end_year"
                label={t("employee.education.end_year", "End Year")}
                error={fieldMessage(t, errors.end_year?.message)}
              >
                <Input
                  {...register("end_year")}
                  inputMode="numeric"
                  placeholder="2019"
                  className="mt-1"
                />
              </FormField>

              <FormField
                id="edu_gpa"
                label={t("employee.education.gpa", "GPA")}
                error={fieldMessage(t, errors.gpa?.message)}
              >
                <Input
                  {...register("gpa")}
                  inputMode="decimal"
                  placeholder="3.8"
                  className="mt-1"
                />
              </FormField>
            </div>

            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                onClick={() => setAddOpen(false)}
              >
                {t("common.cancel", "Cancel")}
              </Button>
              <Button type="submit" disabled={isSubmitting}>
                {isSubmitting && (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                )}
                {t("common.add", "Add")}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </Card>
  );
}
