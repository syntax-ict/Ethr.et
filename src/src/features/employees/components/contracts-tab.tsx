"use client";

import { useState } from "react";
import { FileText, Plus, Loader2 } from "lucide-react";
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
import { formatETB } from "@/lib/utils/currency";
import {
  useEmployeeContracts,
  useCreateContract,
  useRenewContract,
  useEndContract,
  type ContractInput,
  type ContractType,
  type EmployeeContract,
} from "@/features/employees/api";
import { toast } from "sonner";

const CONTRACT_TYPES: ContractType[] = [
  "probation",
  "fixed_term",
  "permanent",
  "casual",
  "consultancy",
];

function contractTypeLabel(
  type: ContractType,
  t: ReturnType<typeof useT>["t"],
) {
  const fallbacks: Record<ContractType, string> = {
    probation: "Probation",
    fixed_term: "Fixed term",
    permanent: "Permanent",
    casual: "Casual",
    consultancy: "Consultancy",
  };
  return t(`employee.contracts.type.${type}`, fallbacks[type]);
}

export function ContractsTab({ employeeId }: { employeeId: string }) {
  const { t } = useT();
  const query = useEmployeeContracts(employeeId);
  const [addOpen, setAddOpen] = useState(false);

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h2 className="text-lg font-semibold">
          {t("employee.contracts.title", "Contracts")}
        </h2>
        <Button size="sm" onClick={() => setAddOpen(true)}>
          <Plus className="mr-2 h-4 w-4" />
          {t("employee.contracts.add", "Add contract")}
        </Button>
      </div>

      <QueryBoundary
        query={query}
        empty={
          <EmptyState
            icon={FileText}
            title={t("employee.contracts.empty_title", "No contracts")}
            description={t(
              "employee.contracts.empty_desc",
              "Probationary, fixed-term and permanent contracts will appear here.",
            )}
          />
        }
      >
        {(contracts) => (
          <div className="space-y-3">
            {contracts.map((c) => (
              <ContractCard
                key={c.public_id}
                employeeId={employeeId}
                contract={c}
              />
            ))}
          </div>
        )}
      </QueryBoundary>

      <ContractDialog
        mode="create"
        employeeId={employeeId}
        open={addOpen}
        onOpenChange={setAddOpen}
      />
    </div>
  );
}

function statusVariant(status: EmployeeContract["status"]) {
  switch (status) {
    case "active":
      return "default" as const;
    case "expired":
    case "terminated_early":
      return "destructive" as const;
    default:
      return "outline" as const;
  }
}

function ContractCard({
  employeeId,
  contract: c,
}: {
  employeeId: string;
  contract: EmployeeContract;
}) {
  const { t } = useT();
  const [renewOpen, setRenewOpen] = useState(false);
  const endContract = useEndContract(employeeId);

  function submitEnd(status: "expired" | "terminated_early") {
    endContract.mutate(
      { contractId: c.public_id, input: { status } },
      {
        onSuccess: () =>
          toast.success(t("employee.contracts.ended", "Contract ended")),
        onError: () =>
          toast.error(
            t("employee.contracts.end_failed", "Could not end contract"),
          ),
      },
    );
  }

  return (
    <Card>
      <CardContent className="space-y-2 p-4">
        <div className="flex flex-wrap items-center gap-2">
          <Badge variant="outline">
            {contractTypeLabel(c.contract_type, t)}
          </Badge>
          <Badge variant={statusVariant(c.status)}>
            {t(`employee.contracts.status.${c.status}`, c.status)}
          </Badge>
          {c.status === "active" && c.expires_soon && (
            <Badge variant="secondary">
              {t("employee.contracts.expires_soon", "Expiring soon")}
            </Badge>
          )}
          {c.reference_number && (
            <span className="ml-auto font-mono text-xs text-muted-foreground">
              {c.reference_number}
            </span>
          )}
        </div>

        <p className="text-sm text-muted-foreground">
          {c.start_date}
          {c.end_date ? ` → ${c.end_date}` : ""}
          {c.status === "active" &&
            c.days_until_expiry !== null &&
            ` (${t("employee.contracts.days_left", ":count days left", { count: c.days_until_expiry })})`}
        </p>

        {c.salary_cents !== null && (
          <p className="text-sm text-foreground">{formatETB(c.salary_cents)}</p>
        )}
        {c.terms && <p className="text-sm text-muted-foreground">{c.terms}</p>}
        {c.end_notes && (
          <p className="text-sm text-muted-foreground">{c.end_notes}</p>
        )}

        {c.status === "active" && (
          <div className="flex flex-wrap gap-2 pt-1">
            <Button
              size="sm"
              variant="outline"
              onClick={() => setRenewOpen(true)}
            >
              {t("employee.contracts.renew", "Renew")}
            </Button>
            <Button
              size="sm"
              variant="outline"
              onClick={() => submitEnd("expired")}
              disabled={endContract.isPending}
            >
              {endContract.isPending && (
                <Loader2 className="mr-2 h-3.5 w-3.5 animate-spin" />
              )}
              {t("employee.contracts.mark_expired", "Mark expired")}
            </Button>
            <Button
              size="sm"
              variant="outline"
              onClick={() => submitEnd("terminated_early")}
              disabled={endContract.isPending}
            >
              {t("employee.contracts.end_early", "End early")}
            </Button>
          </div>
        )}
      </CardContent>

      <ContractDialog
        mode="renew"
        employeeId={employeeId}
        contractId={c.public_id}
        previousSalaryCents={c.salary_cents}
        open={renewOpen}
        onOpenChange={setRenewOpen}
      />
    </Card>
  );
}

const contractSchema = z
  .object({
    contract_type: z.enum([
      "probation",
      "fixed_term",
      "permanent",
      "casual",
      "consultancy",
    ]),
    reference_number: rules.text(100),
    start_date: rules.date(),
    end_date: rules.optionalDate(),
    // Blank means "carry the previous contract's salary", which is what a
    // renewal usually wants — so it is optional rather than required.
    salary_cents: z
      .string()
      .trim()
      .refine(
        (v) => v === "" || /^\d+(\.\d{1,2})?$/.test(v),
        "validation.amount",
      ),
    terms: rules.text(2000),
  })
  .superRefine((data, ctx) => {
    // Mirrors the server's "end date required unless permanent" rule. A
    // permanent contract has no end date by definition; every other type is a
    // term, and a term with no end is not a term.
    if (data.contract_type !== "permanent" && data.end_date === "") {
      ctx.addIssue({
        code: "custom",
        path: ["end_date"],
        message: "employee.contracts.end_date_required",
      });
    }
    if (
      data.end_date !== "" &&
      data.start_date !== "" &&
      data.end_date < data.start_date
    ) {
      ctx.addIssue({
        code: "custom",
        path: ["end_date"],
        message: "validation.date_range",
      });
    }
  });
type ContractValues = z.infer<typeof contractSchema>;

const EMPTY_CONTRACT: ContractValues = {
  contract_type: "fixed_term",
  reference_number: "",
  start_date: "",
  end_date: "",
  salary_cents: "",
  terms: "",
};

function ContractDialog({
  mode,
  employeeId,
  contractId,
  previousSalaryCents,
  open,
  onOpenChange,
}: {
  mode: "create" | "renew";
  employeeId: string;
  contractId?: string;
  previousSalaryCents?: number | null;
  open: boolean;
  onOpenChange: (v: boolean) => void;
}) {
  const { t } = useT();
  const create = useCreateContract(employeeId);
  const renew = useRenewContract(employeeId);

  const {
    register,
    control,
    submit,
    reset,
    watch,
    rootError,
    formState: { errors, isSubmitting },
  } = useZodForm<ContractValues>({
    schema: contractSchema,
    defaultValues: EMPTY_CONTRACT,
  });

  const contractType = watch("contract_type");
  const requiresEndDate = contractType !== "permanent";

  async function onSubmit(values: ContractValues) {
    const input: ContractInput = {
      contract_type: values.contract_type,
      reference_number: values.reference_number || null,
      start_date: values.start_date,
      end_date: values.end_date || null,
      salary_cents: values.salary_cents
        ? Math.round(Number(values.salary_cents) * 100)
        : (previousSalaryCents ?? null),
      terms: values.terms || null,
    };

    if (mode === "create") {
      await create.mutateAsync(input);
    } else if (contractId) {
      await renew.mutateAsync({ contractId, input });
    }

    toast.success(
      mode === "create"
        ? t("employee.contracts.added", "Contract added")
        : t("employee.contracts.renewed", "Contract renewed"),
    );
    reset(EMPTY_CONTRACT);
    onOpenChange(false);
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>
            {mode === "create"
              ? t("employee.contracts.add", "Add contract")
              : t("employee.contracts.renew", "Renew")}
          </DialogTitle>
        </DialogHeader>

        <form
          onSubmit={submit(
            onSubmit,
            t("employee.contracts.save_failed", "Could not save contract"),
          )}
          className="space-y-3"
          noValidate
        >
          <FormErrorSummary message={rootError} />

          <div className="grid grid-cols-2 gap-3">
            <FormField
              id="contract_type"
              label={
                <span className="text-xs">
                  {t("employee.contracts.field.type", "Contract type")}
                </span>
              }
              required
              error={fieldMessage(t, errors.contract_type?.message)}
            >
              {(control_) => (
                <Controller
                  name="contract_type"
                  control={control}
                  render={({ field }) => (
                    <Select value={field.value} onValueChange={field.onChange}>
                      <SelectTrigger {...control_} className="mt-1">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        {CONTRACT_TYPES.map((type) => (
                          <SelectItem key={type} value={type}>
                            {contractTypeLabel(type, t)}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  )}
                />
              )}
            </FormField>

            <FormField
              id="contract_reference"
              label={
                <span className="text-xs">
                  {t("employee.contracts.field.reference", "Reference no.")}
                </span>
              }
              error={fieldMessage(t, errors.reference_number?.message)}
            >
              <Input {...register("reference_number")} className="mt-1" />
            </FormField>

            <FormField
              id="contract_start_date"
              label={
                <span className="text-xs">
                  {t("employee.contracts.field.start_date", "Start date")}
                </span>
              }
              required
              error={fieldMessage(t, errors.start_date?.message)}
            >
              {(control_) => (
                <Controller
                  name="start_date"
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
              id="contract_end_date"
              label={
                <span className="text-xs">
                  {t("employee.contracts.field.end_date", "End date")}
                </span>
              }
              required={requiresEndDate}
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
                      className="mt-1"
                    />
                  )}
                />
              )}
            </FormField>

            <FormField
              id="contract_salary"
              label={
                <span className="text-xs">
                  {t("employee.contracts.field.salary", "Salary (ETB)")}
                </span>
              }
              error={fieldMessage(t, errors.salary_cents?.message)}
            >
              <Input
                {...register("salary_cents")}
                type="number"
                step="0.01"
                min="0"
                className="mt-1"
              />
            </FormField>
          </div>

          <FormField
            id="contract_terms"
            label={
              <span className="text-xs">
                {t("employee.contracts.field.terms", "Terms")}
              </span>
            }
            error={fieldMessage(t, errors.terms?.message)}
          >
            <Textarea {...register("terms")} rows={2} className="mt-1" />
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
              {mode === "create"
                ? t("employee.contracts.add", "Add contract")
                : t("employee.contracts.renew", "Renew")}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
