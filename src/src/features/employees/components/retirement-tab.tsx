"use client";

import { useState } from "react";
import { Plus, Landmark, Loader2 } from "lucide-react";
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
import { FormField } from "@/components/patterns/FormField";
import { FormErrorSummary } from "@/components/patterns/FormErrorSummary";
import { Controller } from "react-hook-form";
import { useZodForm } from "@/lib/forms/use-zod-form";
import { rules, fieldMessage } from "@/lib/forms/rules";
import { z } from "zod";
import { useT } from "@/lib/i18n/useT";
import { usePermissions } from "@/lib/hooks/usePermissions";
import {
  useEmployeeRetirementCases,
  useInitiateRetirementCase,
  useAddRetirementNote,
  useDecideRetirementCase,
  useFinalizeRetirementCase,
  useCancelRetirementCase,
  type RetirementCase,
  type RetirementType,
} from "@/features/employees/api";
import { toast } from "sonner";

const RETIREMENT_TYPES: RetirementType[] = ["mandatory", "voluntary", "early"];

function retirementTypeLabel(
  type: RetirementType,
  t: ReturnType<typeof useT>["t"],
) {
  const fallbacks: Record<RetirementType, string> = {
    mandatory: "Mandatory",
    voluntary: "Voluntary",
    early: "Early",
  };
  return t(`employee.retirement.type.${type}`, fallbacks[type]);
}

const initiateSchema = z.object({
  retirement_type: z.enum(
    RETIREMENT_TYPES as [RetirementType, ...RetirementType[]],
  ),
  reason: rules.text(2000),
});
type InitiateValues = z.infer<typeof initiateSchema>;

const retirementDecisionSchema = z.object({
  decision: z.enum(["approved", "rejected"]),
  decision_notes: rules.text(2000),
});
type RetirementDecisionValues = z.infer<typeof retirementDecisionSchema>;

const finalizeSchema = z.object({
  // Finalizing drives a real `EmployeeTransition`, so the date is not
  // decoration — it is when the person stops being employed.
  effective_date: rules.date(),
});
type FinalizeValues = z.infer<typeof finalizeSchema>;

const cancelSchema = z.object({ notes: rules.text(2000) });
type CancelValues = z.infer<typeof cancelSchema>;

function statusVariant(status: RetirementCase["status"]) {
  switch (status) {
    case "finalized":
      return "outline" as const;
    case "rejected":
    case "cancelled":
      return "destructive" as const;
    default:
      return "default" as const;
  }
}

export function RetirementTab({ employeeId }: { employeeId: string }) {
  const { t } = useT();
  const { can } = usePermissions();
  const query = useEmployeeRetirementCases(employeeId);
  const [initiateOpen, setInitiateOpen] = useState(false);

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h2 className="text-lg font-semibold">
          {t("employee.retirement.title", "Retirement")}
        </h2>
        {can.manageRetirementCases && (
          <Button size="sm" onClick={() => setInitiateOpen(true)}>
            <Plus className="mr-2 h-4 w-4" />
            {t("employee.retirement.initiate", "Initiate retirement")}
          </Button>
        )}
      </div>

      <QueryBoundary
        query={query}
        empty={
          <EmptyState
            icon={Landmark}
            title={t("employee.retirement.empty_title", "No retirement cases")}
            description={t(
              "employee.retirement.empty_desc",
              "Retirement eligibility, review and finalization will appear here.",
            )}
          />
        }
      >
        {(cases) => (
          <div className="space-y-3">
            {cases.map((c) => (
              <CaseCard key={c.public_id} employeeId={employeeId} case={c} />
            ))}
          </div>
        )}
      </QueryBoundary>

      {can.manageRetirementCases && (
        <InitiateDialog
          employeeId={employeeId}
          open={initiateOpen}
          onOpenChange={setInitiateOpen}
        />
      )}
    </div>
  );
}

function CaseCard({
  employeeId,
  case: c,
}: {
  employeeId: string;
  case: RetirementCase;
}) {
  const { t } = useT();
  const { can } = usePermissions();
  const [noteText, setNoteText] = useState("");
  const [decisionOpen, setDecisionOpen] = useState(false);
  const [finalizeOpen, setFinalizeOpen] = useState(false);
  const [cancelOpen, setCancelOpen] = useState(false);

  const addNote = useAddRetirementNote(employeeId);

  const canManage = can.manageRetirementCases;
  const canDecide =
    canManage && (c.status === "initiated" || c.status === "under_review");
  const canFinalize = canManage && c.status === "approved";
  const canCancel =
    canManage &&
    (c.status === "initiated" ||
      c.status === "under_review" ||
      c.status === "approved");
  const canAddNote =
    canManage && (c.status === "initiated" || c.status === "under_review");

  function submitNote() {
    const note = noteText.trim();
    if (!note) return;
    addNote.mutate(
      { caseId: c.public_id, input: { note } },
      {
        onSuccess: () => setNoteText(""),
        onError: () =>
          toast.error(
            t("employee.retirement.note_failed", "Could not add note"),
          ),
      },
    );
  }

  return (
    <Card>
      <CardContent className="space-y-3 p-4">
        <div className="flex flex-wrap items-center gap-2">
          <Badge variant="outline">
            {retirementTypeLabel(c.retirement_type, t)}
          </Badge>
          <Badge variant={statusVariant(c.status)}>
            {t(`employee.retirement.status.${c.status}`, c.status)}
          </Badge>
          <span className="text-sm text-muted-foreground">
            {t("employee.retirement.field.service_years", "Service")}:{" "}
            {c.service_years} {t("employee.retirement.years", "yrs")}
          </span>
          {c.eligible_retirement_date && (
            <span className="text-sm text-muted-foreground">
              {t("employee.retirement.field.eligible_date", "Eligible")}:{" "}
              {c.eligible_retirement_date}
            </span>
          )}
        </div>

        {c.reason && <p className="text-sm text-foreground">{c.reason}</p>}

        {c.notes && (
          <p className="whitespace-pre-line border-l-2 border-border/60 pl-3 text-sm text-muted-foreground">
            {c.notes}
          </p>
        )}

        {c.decision && (
          <div className="rounded-md bg-muted/40 p-2 text-sm">
            <p>
              <span className="font-medium text-foreground">
                {t("employee.retirement.field.decision", "Decision")}:
              </span>{" "}
              {t(`employee.retirement.decision.${c.decision}`, c.decision)}
            </p>
            {c.decision_notes && (
              <p className="text-muted-foreground">{c.decision_notes}</p>
            )}
          </div>
        )}

        {c.finalized_at && (
          <p className="text-sm text-muted-foreground">
            {t("employee.retirement.finalized_on", "Finalized")}:{" "}
            {c.finalized_at}
          </p>
        )}

        {canAddNote && (
          <div className="flex gap-2">
            <Input
              value={noteText}
              onChange={(e) => setNoteText(e.target.value)}
              placeholder={t(
                "employee.retirement.note_placeholder",
                "Add a review note...",
              )}
              className="h-8 text-sm"
            />
            <Button
              size="sm"
              variant="outline"
              onClick={submitNote}
              disabled={addNote.isPending || !noteText.trim()}
            >
              {t("employee.retirement.add_note", "Add note")}
            </Button>
          </div>
        )}

        {(canDecide || canFinalize || canCancel) && (
          <div className="flex flex-wrap gap-2 pt-1">
            {canDecide && (
              <Button
                size="sm"
                variant="outline"
                onClick={() => setDecisionOpen(true)}
              >
                {t("employee.retirement.record_decision", "Record decision")}
              </Button>
            )}
            {canFinalize && (
              <Button
                size="sm"
                variant="outline"
                onClick={() => setFinalizeOpen(true)}
              >
                {t("employee.retirement.finalize", "Finalize")}
              </Button>
            )}
            {canCancel && (
              <Button
                size="sm"
                variant="outline"
                onClick={() => setCancelOpen(true)}
              >
                {t("employee.retirement.cancel_case", "Cancel case")}
              </Button>
            )}
          </div>
        )}
      </CardContent>

      <DecisionDialog
        employeeId={employeeId}
        caseId={c.public_id}
        open={decisionOpen}
        onOpenChange={setDecisionOpen}
      />
      <FinalizeDialog
        employeeId={employeeId}
        caseId={c.public_id}
        open={finalizeOpen}
        onOpenChange={setFinalizeOpen}
      />
      <CancelDialog
        employeeId={employeeId}
        caseId={c.public_id}
        open={cancelOpen}
        onOpenChange={setCancelOpen}
      />
    </Card>
  );
}

function InitiateDialog({
  employeeId,
  open,
  onOpenChange,
}: {
  employeeId: string;
  open: boolean;
  onOpenChange: (v: boolean) => void;
}) {
  const { t } = useT();
  const initiate = useInitiateRetirementCase(employeeId);

  const {
    register,
    control,
    submit,
    reset,
    rootError,
    formState: { errors, isSubmitting },
  } = useZodForm<InitiateValues>({
    schema: initiateSchema,
    defaultValues: { retirement_type: "mandatory", reason: "" },
  });

  async function onSubmit(values: InitiateValues) {
    // `RetirementCaseService` rejects a second open case for the same employee,
    // and that refusal now shows in the dialog instead of a toast.
    await initiate.mutateAsync({
      retirement_type: values.retirement_type,
      reason: values.reason || null,
    });
    toast.success(
      t("employee.retirement.initiated", "Retirement case initiated"),
    );
    reset({ retirement_type: "mandatory", reason: "" });
    onOpenChange(false);
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>
            {t("employee.retirement.initiate", "Initiate retirement")}
          </DialogTitle>
        </DialogHeader>

        <form
          onSubmit={submit(
            onSubmit,
            t(
              "employee.retirement.initiate_failed",
              "Could not initiate retirement case",
            ),
          )}
          className="space-y-3"
          noValidate
        >
          <FormErrorSummary message={rootError} />

          <FormField
            id="retirement_type"
            label={
              <span className="text-xs">
                {t("employee.retirement.field.type", "Retirement type")}
              </span>
            }
            required
            error={fieldMessage(t, errors.retirement_type?.message)}
          >
            {(control_) => (
              <Controller
                name="retirement_type"
                control={control}
                render={({ field }) => (
                  <Select value={field.value} onValueChange={field.onChange}>
                    <SelectTrigger {...control_} className="mt-1">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {RETIREMENT_TYPES.map((type) => (
                        <SelectItem key={type} value={type}>
                          {retirementTypeLabel(type, t)}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                )}
              />
            )}
          </FormField>

          <FormField
            id="retirement-initiate-reason"
            label={
              <span className="text-xs">
                {t("employee.retirement.field.reason", "Reason")}
              </span>
            }
            error={fieldMessage(t, errors.reason?.message)}
          >
            <Textarea {...register("reason")} rows={2} className="mt-1" />
          </FormField>

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
              {t("employee.retirement.initiate", "Initiate retirement")}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

function DecisionDialog({
  employeeId,
  caseId,
  open,
  onOpenChange,
}: {
  employeeId: string;
  caseId: string;
  open: boolean;
  onOpenChange: (v: boolean) => void;
}) {
  const { t } = useT();
  const decide = useDecideRetirementCase(employeeId);

  const {
    register,
    control,
    submit,
    reset,
    rootError,
    formState: { errors, isSubmitting },
  } = useZodForm<RetirementDecisionValues>({
    schema: retirementDecisionSchema,
    defaultValues: { decision: "approved", decision_notes: "" },
  });

  async function onSubmit(values: RetirementDecisionValues) {
    await decide.mutateAsync({
      caseId,
      input: {
        decision: values.decision,
        decision_notes: values.decision_notes || null,
      },
    });
    toast.success(
      t("employee.retirement.decision_recorded", "Decision recorded"),
    );
    reset({ decision: "approved", decision_notes: "" });
    onOpenChange(false);
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>
            {t("employee.retirement.record_decision", "Record decision")}
          </DialogTitle>
        </DialogHeader>

        <form
          onSubmit={submit(
            onSubmit,
            t(
              "employee.retirement.decision_failed",
              "Could not record decision",
            ),
          )}
          className="space-y-3"
          noValidate
        >
          <FormErrorSummary message={rootError} />

          <FormField
            id="retirement_decision"
            label={
              <span className="text-xs">
                {t("employee.retirement.field.decision", "Decision")}
              </span>
            }
            required
            error={fieldMessage(t, errors.decision?.message)}
          >
            {(control_) => (
              <Controller
                name="decision"
                control={control}
                render={({ field }) => (
                  <Select value={field.value} onValueChange={field.onChange}>
                    <SelectTrigger {...control_} className="mt-1">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="approved">
                        {t("employee.retirement.decision.approved", "Approved")}
                      </SelectItem>
                      <SelectItem value="rejected">
                        {t("employee.retirement.decision.rejected", "Rejected")}
                      </SelectItem>
                    </SelectContent>
                  </Select>
                )}
              />
            )}
          </FormField>

          <FormField
            id="retirement-decision-notes"
            label={
              <span className="text-xs">
                {t("employee.retirement.field.decision_notes", "Notes")}
              </span>
            }
            error={fieldMessage(t, errors.decision_notes?.message)}
          >
            <Textarea
              {...register("decision_notes")}
              rows={2}
              className="mt-1"
            />
          </FormField>

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
              {t("employee.retirement.record_decision", "Record decision")}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

function FinalizeDialog({
  employeeId,
  caseId,
  open,
  onOpenChange,
}: {
  employeeId: string;
  caseId: string;
  open: boolean;
  onOpenChange: (v: boolean) => void;
}) {
  const { t } = useT();
  const finalize = useFinalizeRetirementCase(employeeId);

  const {
    control,
    submit,
    reset,
    rootError,
    formState: { errors, isSubmitting },
  } = useZodForm<FinalizeValues>({
    schema: finalizeSchema,
    defaultValues: { effective_date: "" },
  });

  async function onSubmit(values: FinalizeValues) {
    // `finalize()` fails loudly rather than no-opping if the employee already
    // left through another transition — that refusal belongs in the dialog, not
    // in a toast over a form that has already closed.
    await finalize.mutateAsync({
      caseId,
      input: { effective_date: values.effective_date },
    });
    toast.success(
      t(
        "employee.retirement.finalized",
        "Retirement finalized — employee status updated",
      ),
    );
    reset({ effective_date: "" });
    onOpenChange(false);
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>
            {t("employee.retirement.finalize", "Finalize retirement")}
          </DialogTitle>
        </DialogHeader>

        <form
          onSubmit={submit(
            onSubmit,
            t("employee.retirement.finalize_failed", "Could not finalize"),
          )}
          className="space-y-3"
          noValidate
        >
          <FormErrorSummary message={rootError} />

          <FormField
            id="retirement_effective_date"
            label={
              <span className="text-xs">
                {t(
                  "employee.retirement.field.effective_date",
                  "Effective date",
                )}
              </span>
            }
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
                    min={new Date().toISOString().slice(0, 10)}
                    value={field.value}
                    onChange={field.onChange}
                    className="mt-1"
                  />
                )}
              />
            )}
          </FormField>

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
              {t("employee.retirement.finalize", "Finalize")}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

function CancelDialog({
  employeeId,
  caseId,
  open,
  onOpenChange,
}: {
  employeeId: string;
  caseId: string;
  open: boolean;
  onOpenChange: (v: boolean) => void;
}) {
  const { t } = useT();
  const cancel = useCancelRetirementCase(employeeId);

  const {
    register,
    submit,
    reset,
    rootError,
    formState: { errors, isSubmitting },
  } = useZodForm<CancelValues>({
    schema: cancelSchema,
    defaultValues: { notes: "" },
  });

  async function onSubmit(values: CancelValues) {
    await cancel.mutateAsync({
      caseId,
      input: { notes: values.notes || null },
    });
    toast.success(
      t("employee.retirement.cancelled", "Retirement case cancelled"),
    );
    reset({ notes: "" });
    onOpenChange(false);
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>
            {t("employee.retirement.cancel_case", "Cancel case")}
          </DialogTitle>
        </DialogHeader>

        <form
          onSubmit={submit(
            onSubmit,
            t("employee.retirement.cancel_failed", "Could not cancel case"),
          )}
          className="space-y-3"
          noValidate
        >
          <FormErrorSummary message={rootError} />

          <FormField
            id="retirement-cancel-notes"
            label={
              <span className="text-xs">
                {t("employee.retirement.field.cancel_notes", "Notes")}
              </span>
            }
            error={fieldMessage(t, errors.notes?.message)}
          >
            <Textarea {...register("notes")} rows={2} className="mt-1" />
          </FormField>

          <DialogFooter>
            <Button
              type="button"
              variant="outline"
              onClick={() => onOpenChange(false)}
            >
              {t("common.no", "No")}
            </Button>
            <Button type="submit" variant="destructive" disabled={isSubmitting}>
              {isSubmitting && (
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
              )}
              {t("employee.retirement.cancel_case", "Cancel case")}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
