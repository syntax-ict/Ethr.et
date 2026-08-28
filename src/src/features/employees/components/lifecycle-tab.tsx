"use client";

import { useState } from "react";
import { Loader2, GitCommit, ArrowRight, AlertCircle } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { DualCalendarDateInput } from "@/components/shared/dual-calendar-date-input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { Textarea } from "@/components/ui/textarea";
import { Badge } from "@/components/ui/badge";
import { cn } from "@/lib/utils";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from "@/components/ui/dialog";
import { FormField } from "@/components/patterns/FormField";
import { FormErrorSummary } from "@/components/patterns/FormErrorSummary";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { Controller } from "react-hook-form";
import { apiClient } from "@/api/client";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useT } from "@/lib/i18n/useT";
import { useZodForm } from "@/lib/forms/use-zod-form";
import { rules, fieldMessage } from "@/lib/forms/rules";
import { z } from "zod";
import { toast } from "sonner";

// ── LIFECYCLE TAB ──────────────────────────────────────────────

interface Transition {
  public_id: string;
  from_status: string;
  to_status: string;
  reason: string | null;
  effective_date: string;
  approved_by?: { name?: string; email?: string };
  created_at: string;
}

const ALLOWED_TRANSITIONS: Record<string, string[]> = {
  hired: ["probation", "confirmed"],
  probation: ["confirmed", "terminated"],
  confirmed: ["suspended", "resigned", "terminated", "retired"],
  suspended: ["confirmed", "terminated"],
  resigned: [],
  terminated: [],
  retired: [],
};

const STATUS_DOT_COLOR: Record<string, string> = {
  hired: "bg-info",
  probation: "bg-brand-accent",
  confirmed: "bg-success",
  suspended: "bg-warning",
  resigned: "bg-muted-foreground",
  terminated: "bg-destructive",
  retired: "bg-primary",
};

const STATUS_LABEL: Record<string, string> = {
  hired: "Hired",
  probation: "Probation",
  confirmed: "Confirmed",
  suspended: "Suspended",
  resigned: "Resigned",
  terminated: "Terminated",
  retired: "Retired",
};

const transitionSchema = z.object({
  // The choice is made by clicking a status card, so there is no control to
  // blur — an empty value can only be caught on submit, which is exactly when
  // the message below appears.
  to_status: z.string().min(1, "employee.lifecycle.status_required"),
  reason: rules.text(1000),
  effective_date: rules.date(),
});
type TransitionValues = z.infer<typeof transitionSchema>;

const emptyTransition = (): TransitionValues => ({
  to_status: "",
  reason: "",
  effective_date: new Date().toISOString().split("T")[0],
});

export function LifecycleTab({
  employeeId,
  currentStatus,
}: {
  employeeId: string;
  currentStatus: string;
}) {
  const { t } = useT();
  const queryClient = useQueryClient();
  const { can } = usePermissions();
  const canTransition = can.manageEmployees;
  const [dialogOpen, setDialogOpen] = useState(false);

  const {
    register,
    control,
    submit,
    reset,
    watch,
    setValue,
    rootError,
    formState: { errors, isSubmitting },
  } = useZodForm<TransitionValues>({
    schema: transitionSchema,
    defaultValues: emptyTransition(),
  });

  const selectedStatus = watch("to_status");

  const { data, isLoading } = useQuery({
    queryKey: ["employee", employeeId, "transitions"],
    queryFn: async () => {
      const { data } = await apiClient.get(
        `/employees/${employeeId}/transitions`,
      );
      return data;
    },
  });

  const transitionMut = useMutation({
    mutationFn: async (values: TransitionValues) => {
      const { data } = await apiClient.post(
        `/employees/${employeeId}/transition`,
        values,
      );
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["employee", employeeId] });
      queryClient.invalidateQueries({ queryKey: ["employees"] });
      toast.success(
        t("employee.lifecycle.transitioned", "Status transitioned"),
      );
      setDialogOpen(false);
      reset(emptyTransition());
    },
    // The server enforces the same state machine `ALLOWED_TRANSITIONS` mirrors,
    // and it is the authority on whether this particular employee can move —
    // its refusal now stays on screen in the dialog instead of in a toast that
    // outlives the closed form by five seconds.
  });

  const allowed = ALLOWED_TRANSITIONS[currentStatus] ?? [];
  const isTerminal = allowed.length === 0;
  const transitions: Transition[] = Array.isArray(data)
    ? data
    : (data?.data ?? []);

  return (
    <Card>
      <CardHeader className="flex flex-row items-center justify-between">
        <CardTitle className="text-base">
          {t("employee.lifecycle.title", "Employment Lifecycle")}
        </CardTitle>
        {!isTerminal && canTransition && (
          <Button size="sm" onClick={() => setDialogOpen(true)}>
            <GitCommit className="mr-2 h-3 w-3" />{" "}
            {t("employee.lifecycle.transition_btn", "Transition Status")}
          </Button>
        )}
      </CardHeader>
      <CardContent>
        {isTerminal && (
          <div className="mb-4 flex items-start gap-2 rounded-lg border border-status-warning/30 bg-status-warning/5 p-3">
            <AlertCircle className="mt-0.5 h-4 w-4 shrink-0 text-status-warning" />
            <div>
              <p className="text-sm font-medium">
                {t(
                  "employee.lifecycle.terminal_title",
                  "Employee is in a terminal status",
                )}
              </p>
              <p className="text-xs text-muted-foreground">
                Status &ldquo;{STATUS_LABEL[currentStatus]}&rdquo; cannot be
                transitioned further. Re-hire would require a new employee
                record.
              </p>
            </div>
          </div>
        )}

        <div className="space-y-4">
          {/* Current status as the top of the timeline */}
          <div className="flex items-center gap-3 rounded-lg border-2 border-primary bg-primary/5 p-3">
            <div
              className={cn(
                "h-3 w-3 rounded-full",
                STATUS_DOT_COLOR[currentStatus],
              )}
            />
            <div className="flex-1">
              <p className="text-sm font-semibold">
                {t("employee.lifecycle.currently", "Currently:")}{" "}
                {STATUS_LABEL[currentStatus] ?? currentStatus}
              </p>
              <p className="text-xs text-muted-foreground">
                {t("employee.lifecycle.active_status", "Active status")}
              </p>
            </div>
          </div>

          {isLoading ? (
            <div className="space-y-2">
              {Array.from({ length: 2 }).map((_, i) => (
                <Skeleton key={i} className="h-16 w-full" />
              ))}
            </div>
          ) : transitions.length === 0 ? (
            <p className="text-center text-sm text-muted-foreground py-4">
              {t(
                "employee.lifecycle.no_transitions",
                "No transitions yet. Employee is in initial state.",
              )}
            </p>
          ) : (
            <div className="space-y-3">
              <p className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                {t("employee.lifecycle.history", "History")}
              </p>
              <div className="relative space-y-3">
                {/* Vertical line through the timeline */}
                <div className="absolute left-[7px] top-3 bottom-3 w-px bg-border" />
                {transitions
                  .slice()
                  .sort(
                    (a, b) =>
                      new Date(b.effective_date).getTime() -
                      new Date(a.effective_date).getTime(),
                  )
                  .map((t) => (
                    <div key={t.public_id} className="relative flex gap-3 pl-0">
                      <div
                        className={cn(
                          "z-10 mt-1 h-3.5 w-3.5 shrink-0 rounded-full ring-2 ring-background",
                          STATUS_DOT_COLOR[t.to_status],
                        )}
                      />
                      <div className="flex-1 min-w-0 rounded-lg border p-3">
                        <div className="flex flex-wrap items-center gap-2">
                          <Badge
                            variant="outline"
                            className="text-[10px] font-mono"
                          >
                            {STATUS_LABEL[t.from_status] ?? t.from_status}
                          </Badge>
                          <ArrowRight className="h-3 w-3 text-muted-foreground" />
                          <Badge
                            variant="outline"
                            className={cn(
                              "text-[10px] font-mono border-0",
                              STATUS_DOT_COLOR[t.to_status],
                              "text-text-inverse",
                            )}
                          >
                            {STATUS_LABEL[t.to_status] ?? t.to_status}
                          </Badge>
                          <span className="ml-auto text-xs text-muted-foreground">
                            {t.effective_date}
                          </span>
                        </div>
                        {t.reason && (
                          <p className="mt-2 text-sm text-foreground">
                            {t.reason}
                          </p>
                        )}
                        {t.approved_by && (
                          <p className="mt-1 text-xs text-muted-foreground">
                            by{" "}
                            {t.approved_by.name ??
                              t.approved_by.email ??
                              "system"}
                          </p>
                        )}
                      </div>
                    </div>
                  ))}
              </div>
            </div>
          )}
        </div>
      </CardContent>

      <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>
              {t(
                "employee.lifecycle.transition_title",
                "Transition Employee Status",
              )}
            </DialogTitle>
          </DialogHeader>
          <form
            onSubmit={submit(
              (values) => transitionMut.mutateAsync(values),
              t("employee.lifecycle.transition_failed", "Transition failed"),
            )}
            className="space-y-4"
            noValidate
          >
            <FormErrorSummary message={rootError} />
            <div className="rounded-lg border bg-muted/30 p-3 text-sm">
              <p className="text-xs text-muted-foreground">
                {t("employee.lifecycle.current_status_label", "Current status")}
              </p>
              <p className="mt-0.5 font-medium capitalize">
                {STATUS_LABEL[currentStatus] ?? currentStatus}
              </p>
            </div>
            <div>
              <Label id="transition_status_label">
                {t("employee.lifecycle.new_status", "New Status")} *
              </Label>
              <div
                role="radiogroup"
                aria-labelledby="transition_status_label"
                aria-invalid={errors.to_status ? true : undefined}
                className="mt-2 grid gap-2 sm:grid-cols-2"
              >
                {allowed.map((status) => (
                  <button
                    key={status}
                    type="button"
                    role="radio"
                    aria-checked={selectedStatus === status}
                    onClick={() =>
                      setValue("to_status", status, { shouldValidate: true })
                    }
                    className={cn(
                      "flex items-center gap-2 rounded-lg border-2 p-3 text-left transition-colors",
                      selectedStatus === status
                        ? "border-primary bg-primary/5"
                        : "border-border hover:border-primary/50",
                    )}
                  >
                    <div
                      className={cn(
                        "h-2.5 w-2.5 rounded-full",
                        STATUS_DOT_COLOR[status],
                      )}
                    />
                    <span className="text-sm font-medium">
                      {STATUS_LABEL[status]}
                    </span>
                  </button>
                ))}
              </div>
              {errors.to_status?.message && (
                <p
                  role="alert"
                  className="mt-1.5 text-xs font-medium text-destructive"
                >
                  {fieldMessage(t, errors.to_status.message)}
                </p>
              )}
            </div>

            <FormField
              id="transition_effective_date"
              label={t("employee.lifecycle.effective_date", "Effective Date")}
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
                      className="mt-1"
                    />
                  )}
                />
              )}
            </FormField>

            <FormField
              id="transition_reason"
              label={t("employee.lifecycle.reason", "Reason")}
              error={fieldMessage(t, errors.reason?.message)}
            >
              <Textarea
                {...register("reason")}
                placeholder="Optional — context for the transition"
                rows={3}
                className="mt-1"
              />
            </FormField>
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
                {t("employee.lifecycle.apply_transition", "Apply Transition")}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </Card>
  );
}
