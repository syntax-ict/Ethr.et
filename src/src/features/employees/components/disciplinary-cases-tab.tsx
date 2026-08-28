"use client";

import { useState } from "react";
import { Plus, ShieldAlert, Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Input } from "@/components/ui/input";
import { DualCalendarDateInput } from "@/components/shared/dual-calendar-date-input";
import { Label } from "@/components/ui/label";
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
  useEmployeeDisciplinaryCases,
  useOpenDisciplinaryCase,
  useAddDisciplinaryNote,
  useRecordDisciplinaryDecision,
  useFileDisciplinaryAppeal,
  useResolveDisciplinaryAppeal,
  useCloseDisciplinaryCase,
  type DisciplinaryCase,
  type DisciplinaryCategory,
  type DisciplinaryDecision,
  type DisciplinarySanctionType,
} from "@/features/employees/api";
import { toast } from "sonner";

const CATEGORIES: DisciplinaryCategory[] = [
  "misconduct",
  "absenteeism",
  "insubordination",
  "negligence",
  "policy_violation",
  "financial_irregularity",
  "harassment",
  "other",
];

const DECISIONS: DisciplinaryDecision[] = [
  "guilty",
  "not_guilty",
  "inconclusive",
];

const SANCTIONS: DisciplinarySanctionType[] = [
  "verbal_warning",
  "written_warning",
  "suspension",
  "demotion",
  "salary_deduction",
  "termination",
];

function categoryLabel(
  category: DisciplinaryCategory,
  t: ReturnType<typeof useT>["t"],
) {
  const fallbacks: Record<DisciplinaryCategory, string> = {
    misconduct: "Misconduct",
    absenteeism: "Absenteeism",
    insubordination: "Insubordination",
    negligence: "Negligence",
    policy_violation: "Policy violation",
    financial_irregularity: "Financial irregularity",
    harassment: "Harassment",
    other: "Other",
  };
  return t(`employee.discipline.category.${category}`, fallbacks[category]);
}

function decisionLabel(
  decision: DisciplinaryDecision,
  t: ReturnType<typeof useT>["t"],
) {
  const fallbacks: Record<DisciplinaryDecision, string> = {
    guilty: "Guilty",
    not_guilty: "Not guilty",
    inconclusive: "Inconclusive",
  };
  return t(`employee.discipline.decision.${decision}`, fallbacks[decision]);
}

function sanctionLabel(
  sanction: DisciplinarySanctionType,
  t: ReturnType<typeof useT>["t"],
) {
  const fallbacks: Record<DisciplinarySanctionType, string> = {
    verbal_warning: "Verbal warning",
    written_warning: "Written warning",
    suspension: "Suspension",
    demotion: "Demotion",
    salary_deduction: "Salary deduction",
    termination: "Termination",
  };
  return t(`employee.discipline.sanction.${sanction}`, fallbacks[sanction]);
}

function statusVariant(status: DisciplinaryCase["status"]) {
  switch (status) {
    case "closed":
      return "outline" as const;
    case "appealed":
      return "secondary" as const;
    default:
      return "default" as const;
  }
}

export function DisciplinaryCasesTab({ employeeId }: { employeeId: string }) {
  const { t } = useT();
  const { can } = usePermissions();
  const query = useEmployeeDisciplinaryCases(employeeId);
  const [openDialog, setOpenDialog] = useState(false);

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h2 className="text-lg font-semibold">
          {t("employee.discipline.title", "Disciplinary Cases")}
        </h2>
        {can.manageDisciplinaryCases && (
          <Button size="sm" onClick={() => setOpenDialog(true)}>
            <Plus className="mr-2 h-4 w-4" />
            {t("employee.discipline.open_case", "Open case")}
          </Button>
        )}
      </div>

      <QueryBoundary
        query={query}
        empty={
          <EmptyState
            icon={ShieldAlert}
            title={t(
              "employee.discipline.empty_title",
              "No disciplinary cases",
            )}
            description={t(
              "employee.discipline.empty_desc",
              "Offences, investigations, decisions and appeals will appear here.",
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

      {can.manageDisciplinaryCases && (
        <OpenCaseDialog
          employeeId={employeeId}
          open={openDialog}
          onOpenChange={setOpenDialog}
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
  case: DisciplinaryCase;
}) {
  const { t } = useT();
  const { can } = usePermissions();
  const [noteText, setNoteText] = useState("");
  const [decisionOpen, setDecisionOpen] = useState(false);
  const [appealOpen, setAppealOpen] = useState(false);
  const [resolveOpen, setResolveOpen] = useState(false);

  const addNote = useAddDisciplinaryNote(employeeId);
  const close = useCloseDisciplinaryCase(employeeId);

  const canManage = can.manageDisciplinaryCases;
  const canDecide =
    canManage && (c.status === "reported" || c.status === "investigating");
  const canAppeal = canManage && c.status === "decided";
  const canResolveAppeal = canManage && c.status === "appealed";
  const canClose =
    canManage &&
    (c.status === "reported" ||
      c.status === "investigating" ||
      c.status === "decided");
  const canAddNote = canManage && c.status !== "closed";

  function submitNote() {
    const note = noteText.trim();
    if (!note) return;
    addNote.mutate(
      { caseId: c.public_id, input: { note } },
      {
        onSuccess: () => setNoteText(""),
        onError: () =>
          toast.error(
            t("employee.discipline.note_failed", "Could not add note"),
          ),
      },
    );
  }

  function submitClose() {
    close.mutate(
      { caseId: c.public_id, input: {} },
      {
        onSuccess: () =>
          toast.success(t("employee.discipline.closed", "Case closed")),
        onError: () =>
          toast.error(
            t("employee.discipline.close_failed", "Could not close case"),
          ),
      },
    );
  }

  return (
    <Card>
      <CardContent className="space-y-3 p-4">
        <div className="flex flex-wrap items-center gap-2">
          <Badge variant="outline">{categoryLabel(c.category, t)}</Badge>
          <Badge variant={statusVariant(c.status)}>
            {t(`employee.discipline.status.${c.status}`, c.status)}
          </Badge>
          <span className="text-sm text-muted-foreground">
            {c.incident_date}
          </span>
          {c.reference_number && (
            <span className="ml-auto font-mono text-xs text-muted-foreground">
              {c.reference_number}
            </span>
          )}
        </div>

        <p className="text-sm text-foreground">{c.description}</p>

        {c.investigation_notes.length > 0 && (
          <ul className="space-y-1 border-l-2 border-border/60 pl-3">
            {c.investigation_notes.map((n, i) => (
              <li key={i} className="text-sm text-muted-foreground">
                <span className="text-foreground">{n.note}</span>
                {n.by_name && ` — ${n.by_name}`}
              </li>
            ))}
          </ul>
        )}

        {c.decision && (
          <div className="rounded-md bg-muted/40 p-2 text-sm">
            <p>
              <span className="font-medium text-foreground">
                {t("employee.discipline.field.decision", "Decision")}:
              </span>{" "}
              {decisionLabel(c.decision, t)}
            </p>
            {c.decision_notes && (
              <p className="text-muted-foreground">{c.decision_notes}</p>
            )}
            {c.sanction_type && (
              <p>
                <span className="font-medium text-foreground">
                  {t("employee.discipline.field.sanction", "Sanction")}:
                </span>{" "}
                {sanctionLabel(c.sanction_type, t)}
                {c.sanction_effective_date && ` (${c.sanction_effective_date})`}
              </p>
            )}
          </div>
        )}

        {c.appeal_status && (
          <div className="rounded-md bg-muted/40 p-2 text-sm">
            <p>
              <span className="font-medium text-foreground">
                {t("employee.discipline.field.appeal", "Appeal")}:
              </span>{" "}
              {t(
                `employee.discipline.appeal_status.${c.appeal_status}`,
                c.appeal_status,
              )}
            </p>
            {c.appeal_grounds && (
              <p className="text-muted-foreground">{c.appeal_grounds}</p>
            )}
            {c.appeal_decision_notes && (
              <p className="text-muted-foreground">{c.appeal_decision_notes}</p>
            )}
          </div>
        )}

        {canAddNote && (
          <div className="flex gap-2">
            <Input
              value={noteText}
              onChange={(e) => setNoteText(e.target.value)}
              placeholder={t(
                "employee.discipline.note_placeholder",
                "Add an investigation note...",
              )}
              className="h-8 text-sm"
            />
            <Button
              size="sm"
              variant="outline"
              onClick={submitNote}
              disabled={addNote.isPending || !noteText.trim()}
            >
              {t("employee.discipline.add_note", "Add note")}
            </Button>
          </div>
        )}

        {(canDecide || canAppeal || canResolveAppeal || canClose) && (
          <div className="flex flex-wrap gap-2 pt-1">
            {canDecide && (
              <Button
                size="sm"
                variant="outline"
                onClick={() => setDecisionOpen(true)}
              >
                {t("employee.discipline.record_decision", "Record decision")}
              </Button>
            )}
            {canAppeal && (
              <Button
                size="sm"
                variant="outline"
                onClick={() => setAppealOpen(true)}
              >
                {t("employee.discipline.file_appeal", "File appeal")}
              </Button>
            )}
            {canResolveAppeal && (
              <Button
                size="sm"
                variant="outline"
                onClick={() => setResolveOpen(true)}
              >
                {t("employee.discipline.resolve_appeal", "Resolve appeal")}
              </Button>
            )}
            {canClose && (
              <Button
                size="sm"
                variant="outline"
                onClick={submitClose}
                disabled={close.isPending}
              >
                {close.isPending && (
                  <Loader2 className="mr-2 h-3.5 w-3.5 animate-spin" />
                )}
                {t("employee.discipline.close_case", "Close case")}
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
      <AppealDialog
        employeeId={employeeId}
        caseId={c.public_id}
        open={appealOpen}
        onOpenChange={setAppealOpen}
      />
      <ResolveAppealDialog
        employeeId={employeeId}
        caseId={c.public_id}
        open={resolveOpen}
        onOpenChange={setResolveOpen}
      />
    </Card>
  );
}

const openCaseSchema = z.object({
  category: z.enum(
    CATEGORIES as [DisciplinaryCategory, ...DisciplinaryCategory[]],
  ),
  // A case is a due-process record; a one-word description is not one. The
  // minimum is deliberately higher than "not blank".
  description: z
    .string()
    .trim()
    .min(10, "employee.discipline.description_short")
    .max(2000, "validation.too_long"),
  incident_date: rules.date(),
  reference_number: rules.text(100),
});
type OpenCaseValues = z.infer<typeof openCaseSchema>;

const EMPTY_OPEN_CASE: OpenCaseValues = {
  category: "misconduct",
  description: "",
  incident_date: "",
  reference_number: "",
};

const decisionSchema = z
  .object({
    decision: z.enum(
      DECISIONS as [DisciplinaryDecision, ...DisciplinaryDecision[]],
    ),
    decision_notes: rules.text(2000),
    // "" means no sanction, which is valid on any decision — so this is a
    // union with the empty string rather than a bare enum, and stays typed as
    // the sanction union for the API call.
    sanction_type: z.union([
      z.literal(""),
      z.enum(
        SANCTIONS as [DisciplinarySanctionType, ...DisciplinarySanctionType[]],
      ),
    ]),
    sanction_details: rules.text(2000),
    sanction_effective_date: rules.optionalDate(),
  })
  .superRefine((data, ctx) => {
    // A sanction with no start date cannot be applied to attendance, payroll
    // or a transition — it would be recorded and then never take effect.
    if (
      data.decision === "guilty" &&
      data.sanction_type !== "" &&
      data.sanction_effective_date === ""
    ) {
      ctx.addIssue({
        code: "custom",
        path: ["sanction_effective_date"],
        message: "employee.discipline.sanction_date_required",
      });
    }
  });
type DecisionValues = z.infer<typeof decisionSchema>;

const EMPTY_DECISION: DecisionValues = {
  decision: "guilty",
  decision_notes: "",
  sanction_type: "",
  sanction_details: "",
  sanction_effective_date: "",
};

const appealSchema = z.object({
  grounds: z
    .string()
    .trim()
    .min(10, "employee.discipline.grounds_short")
    .max(2000, "validation.too_long"),
});
type AppealValues = z.infer<typeof appealSchema>;

function OpenCaseDialog({
  employeeId,
  open,
  onOpenChange,
}: {
  employeeId: string;
  open: boolean;
  onOpenChange: (v: boolean) => void;
}) {
  const { t } = useT();
  const openCase = useOpenDisciplinaryCase(employeeId);

  const {
    register,
    control,
    submit,
    reset,
    rootError,
    formState: { errors, isSubmitting },
  } = useZodForm<OpenCaseValues>({
    schema: openCaseSchema,
    defaultValues: EMPTY_OPEN_CASE,
  });

  async function onSubmit(values: OpenCaseValues) {
    await openCase.mutateAsync({
      category: values.category,
      description: values.description,
      incident_date: values.incident_date,
      reference_number: values.reference_number || null,
    });
    toast.success(t("employee.discipline.opened", "Case opened"));
    reset(EMPTY_OPEN_CASE);
    onOpenChange(false);
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>
            {t("employee.discipline.open_case", "Open case")}
          </DialogTitle>
        </DialogHeader>

        <form
          onSubmit={submit(
            onSubmit,
            t("employee.discipline.open_failed", "Could not open case"),
          )}
          className="space-y-3"
          noValidate
        >
          <FormErrorSummary message={rootError} />

          <div className="grid grid-cols-2 gap-3">
            <FormField
              id="case_category"
              label={
                <span className="text-xs">
                  {t("employee.discipline.field.category", "Category")}
                </span>
              }
              required
              error={fieldMessage(t, errors.category?.message)}
            >
              {(control_) => (
                <Controller
                  name="category"
                  control={control}
                  render={({ field }) => (
                    <Select value={field.value} onValueChange={field.onChange}>
                      <SelectTrigger {...control_} className="mt-1">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        {CATEGORIES.map((cat) => (
                          <SelectItem key={cat} value={cat}>
                            {categoryLabel(cat, t)}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  )}
                />
              )}
            </FormField>

            <FormField
              id="case_incident_date"
              label={
                <span className="text-xs">
                  {t(
                    "employee.discipline.field.incident_date",
                    "Incident date",
                  )}
                </span>
              }
              required
              error={fieldMessage(t, errors.incident_date?.message)}
            >
              {(control_) => (
                <Controller
                  name="incident_date"
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
              id="case_reference"
              label={
                <span className="text-xs">
                  {t("employee.discipline.field.reference", "Reference no.")}
                </span>
              }
              className="col-span-2"
              error={fieldMessage(t, errors.reference_number?.message)}
            >
              <Input {...register("reference_number")} className="mt-1" />
            </FormField>
          </div>

          <FormField
            id="case_description"
            label={
              <span className="text-xs">
                {t("employee.discipline.field.description", "Description")}
              </span>
            }
            required
            error={fieldMessage(t, errors.description?.message)}
          >
            <Textarea {...register("description")} rows={3} className="mt-1" />
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
              {t("employee.discipline.open_case", "Open case")}
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
  const decide = useRecordDisciplinaryDecision(employeeId);

  const {
    register,
    control,
    submit,
    reset,
    watch,
    rootError,
    formState: { errors, isSubmitting },
  } = useZodForm<DecisionValues>({
    schema: decisionSchema,
    defaultValues: EMPTY_DECISION,
  });

  const isGuilty = watch("decision") === "guilty";

  async function onSubmit(values: DecisionValues) {
    const guilty = values.decision === "guilty";

    await decide.mutateAsync({
      caseId,
      input: {
        decision: values.decision,
        decision_notes: values.decision_notes || null,
        // A sanction only means something on a guilty finding. Sending one
        // alongside "not guilty" would record a penalty against someone the
        // process just cleared.
        sanction_type:
          guilty && values.sanction_type ? values.sanction_type : null,
        sanction_details:
          guilty && values.sanction_type
            ? values.sanction_details || null
            : null,
        sanction_effective_date:
          guilty && values.sanction_type
            ? values.sanction_effective_date || null
            : null,
      },
    });

    toast.success(
      t("employee.discipline.decision_recorded", "Decision recorded"),
    );
    reset(EMPTY_DECISION);
    onOpenChange(false);
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>
            {t("employee.discipline.record_decision", "Record decision")}
          </DialogTitle>
        </DialogHeader>

        <form
          onSubmit={submit(
            onSubmit,
            t(
              "employee.discipline.decision_failed",
              "Could not record decision",
            ),
          )}
          className="space-y-3"
          noValidate
        >
          <FormErrorSummary message={rootError} />

          <FormField
            id="decision_outcome"
            label={
              <span className="text-xs">
                {t("employee.discipline.field.decision", "Decision")}
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
                      {DECISIONS.map((d) => (
                        <SelectItem key={d} value={d}>
                          {decisionLabel(d, t)}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                )}
              />
            )}
          </FormField>

          <FormField
            id="decision_notes"
            label={
              <span className="text-xs">
                {t(
                  "employee.discipline.field.decision_notes",
                  "Decision notes",
                )}
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

          {isGuilty && (
            <div className="space-y-3 rounded-md border border-border/60 p-3">
              <FormField
                id="decision_sanction"
                label={
                  <span className="text-xs">
                    {t("employee.discipline.field.sanction", "Sanction")}
                  </span>
                }
                error={fieldMessage(t, errors.sanction_type?.message)}
              >
                {(control_) => (
                  <Controller
                    name="sanction_type"
                    control={control}
                    render={({ field }) => (
                      <Select
                        value={field.value || "__none__"}
                        onValueChange={(v) =>
                          field.onChange(v === "__none__" ? "" : v)
                        }
                      >
                        <SelectTrigger {...control_} className="mt-1">
                          <SelectValue placeholder={t("common.none", "None")} />
                        </SelectTrigger>
                        <SelectContent>
                          <SelectItem value="__none__">
                            {t("common.none", "None")}
                          </SelectItem>
                          {SANCTIONS.map((s) => (
                            <SelectItem key={s} value={s}>
                              {sanctionLabel(s, t)}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                    )}
                  />
                )}
              </FormField>

              {watch("sanction_type") && (
                <>
                  <FormField
                    id="decision_sanction_effective"
                    label={
                      <span className="text-xs">
                        {t(
                          "employee.discipline.field.sanction_effective",
                          "Sanction effective date",
                        )}
                      </span>
                    }
                    required
                    error={fieldMessage(
                      t,
                      errors.sanction_effective_date?.message,
                    )}
                  >
                    {(control_) => (
                      <Controller
                        name="sanction_effective_date"
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
                    id="decision_sanction_details"
                    label={
                      <span className="text-xs">
                        {t(
                          "employee.discipline.field.sanction_details",
                          "Sanction details",
                        )}
                      </span>
                    }
                    error={fieldMessage(t, errors.sanction_details?.message)}
                  >
                    <Textarea
                      {...register("sanction_details")}
                      rows={2}
                      className="mt-1"
                    />
                  </FormField>
                </>
              )}
            </div>
          )}

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
              {t("employee.discipline.record_decision", "Record decision")}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

function AppealDialog({
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
  const appeal = useFileDisciplinaryAppeal(employeeId);

  const {
    register,
    submit,
    reset,
    rootError,
    formState: { errors, isSubmitting },
  } = useZodForm<AppealValues>({
    schema: appealSchema,
    defaultValues: { grounds: "" },
  });

  async function onSubmit(values: AppealValues) {
    await appeal.mutateAsync({ caseId, input: { grounds: values.grounds } });
    toast.success(t("employee.discipline.appeal_filed", "Appeal filed"));
    reset({ grounds: "" });
    onOpenChange(false);
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>
            {t("employee.discipline.file_appeal", "File appeal")}
          </DialogTitle>
        </DialogHeader>

        <form
          onSubmit={submit(
            onSubmit,
            t("employee.discipline.appeal_failed", "Could not file appeal"),
          )}
          className="space-y-3"
          noValidate
        >
          <FormErrorSummary message={rootError} />

          <FormField
            id="appeal_grounds"
            label={
              <span className="text-xs">
                {t("employee.discipline.field.grounds", "Grounds for appeal")}
              </span>
            }
            required
            error={fieldMessage(t, errors.grounds?.message)}
          >
            <Textarea {...register("grounds")} rows={3} className="mt-1" />
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
              {t("employee.discipline.file_appeal", "File appeal")}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

function ResolveAppealDialog({
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
  const resolve = useResolveDisciplinaryAppeal(employeeId);
  const [outcome, setOutcome] = useState<"upheld" | "denied">("upheld");
  const [notes, setNotes] = useState("");

  function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    resolve.mutate(
      { caseId, input: { outcome, decision_notes: notes || null } },
      {
        onSuccess: () => {
          toast.success(
            t("employee.discipline.appeal_resolved", "Appeal resolved"),
          );
          setNotes("");
          onOpenChange(false);
        },
        onError: () =>
          toast.error(
            t("employee.discipline.resolve_failed", "Could not resolve appeal"),
          ),
      },
    );
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>
            {t("employee.discipline.resolve_appeal", "Resolve appeal")}
          </DialogTitle>
        </DialogHeader>

        <form onSubmit={handleSubmit} className="space-y-3">
          <div>
            <Label className="text-xs">
              {t("employee.discipline.field.outcome", "Outcome")}
            </Label>
            <Select
              value={outcome}
              onValueChange={(v) => setOutcome(v as "upheld" | "denied")}
            >
              <SelectTrigger className="mt-1">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="upheld">
                  {t("employee.discipline.appeal_status.upheld", "Upheld")}
                </SelectItem>
                <SelectItem value="denied">
                  {t("employee.discipline.appeal_status.denied", "Denied")}
                </SelectItem>
              </SelectContent>
            </Select>
            {outcome === "upheld" && (
              <p className="mt-1 text-xs text-muted-foreground">
                {t(
                  "employee.discipline.upheld_hint",
                  "Upholding the appeal reverses the sanction.",
                )}
              </p>
            )}
          </div>

          <div>
            <Label className="text-xs">
              {t("employee.discipline.field.decision_notes", "Decision notes")}
            </Label>
            <Textarea
              rows={2}
              value={notes}
              onChange={(e) => setNotes(e.target.value)}
              className="mt-1"
            />
          </div>

          <DialogFooter>
            <Button
              type="button"
              variant="outline"
              onClick={() => onOpenChange(false)}
            >
              {t("common.cancel", "Cancel")}
            </Button>
            <Button type="submit" disabled={resolve.isPending}>
              {resolve.isPending && (
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
              )}
              {t("employee.discipline.resolve_appeal", "Resolve appeal")}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
