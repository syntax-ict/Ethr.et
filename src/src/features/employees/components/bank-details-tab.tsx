"use client";

import { useState } from "react";
import { Loader2, Plus, Trash2, CreditCard } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Checkbox } from "@/components/ui/checkbox";
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
import { Controller } from "react-hook-form";
import { useT } from "@/lib/i18n/useT";
import { useZodForm } from "@/lib/forms/use-zod-form";
import { rules, fieldMessage } from "@/lib/forms/rules";
import { z } from "zod";
import { toast } from "sonner";
import {
  useAddBankDetail,
  useDeleteBankDetail,
  useEmployeeBankDetails,
} from "../api";

const bankSchema = z.object({
  bank_name: rules.requiredText(255),
  branch_name: rules.text(255),
  // Digits and separators only. This is the number the bank export writes into
  // a payment file — `BankExportService` already shipped blank columns once
  // because nothing checked what it was reading, and a transposed or
  // letter-contaminated account number fails silently at the bank instead.
  account_number: z
    .string()
    .trim()
    .min(5, "employee.bank.account_number_short")
    .max(34, "validation.too_long")
    .regex(/^[0-9][0-9\s-]*$/, "employee.bank.account_number_format"),
  // No account-holder field: `employee_bank_details` has no column for one and
  // StoreBankDetailRequest does not accept it, so whatever was typed there was
  // dropped by `validated()` behind a success toast. The holder is the
  // employee whose record this is.
  is_primary: z.boolean(),
});
type BankValues = z.infer<typeof bankSchema>;

const EMPTY_BANK: BankValues = {
  bank_name: "",
  branch_name: "",
  account_number: "",
  is_primary: true,
};

export function BankDetailsTab({ employeeId }: { employeeId: string }) {
  const { t } = useT();
  const [addOpen, setAddOpen] = useState(false);

  const {
    register,
    control,
    submit,
    reset,
    rootError,
    formState: { errors, isSubmitting },
  } = useZodForm<BankValues>({
    schema: bankSchema,
    defaultValues: EMPTY_BANK,
  });

  const { data, isLoading } = useEmployeeBankDetails(employeeId);
  const addBank = useAddBankDetail(employeeId);
  const deleteBank = useDeleteBankDetail(employeeId);

  async function onAdd(values: BankValues) {
    await addBank.mutateAsync(values);
    toast.success(t("employee.bank.added", "Bank details added"));
    setAddOpen(false);
    reset(EMPTY_BANK);
  }

  function onDelete(id: string) {
    deleteBank.mutate(id, {
      onSuccess: () =>
        toast.success(t("employee.bank.deleted", "Bank deleted")),
    });
  }

  const banks = data ?? [];

  /**
   * `is_primary` is the account `BankExportService` pays salary into, and
   * storing a new primary demotes the old one. It used to be hard-wired to
   * true with no control, so adding a second account — a savings account, a
   * spouse's — silently redirected the next payroll into it. It now defaults
   * on only for the first account and is otherwise the user's explicit choice.
   */
  function openAdd() {
    reset({ ...EMPTY_BANK, is_primary: banks.length === 0 });
    setAddOpen(true);
  }

  return (
    <Card>
      <CardHeader className="flex flex-row items-center justify-between">
        <CardTitle className="text-base">
          {t("employee.bank.title", "Bank Details")}
        </CardTitle>
        <Button size="sm" onClick={openAdd}>
          <Plus className="mr-2 h-3 w-3" /> {t("common.add", "Add")}
        </Button>
      </CardHeader>
      <CardContent>
        {isLoading ? (
          <Skeleton className="h-20 w-full" />
        ) : banks.length === 0 ? (
          <EmptyState
            icon={CreditCard}
            title={t("employee.bank.empty_title", "No bank accounts")}
            description={t(
              "employee.bank.empty_desc",
              "Add bank details for salary deposits",
            )}
          />
        ) : (
          <div className="space-y-2">
            {banks.map((b) => (
              <div
                key={b.public_id}
                className="flex items-center justify-between rounded-lg border p-3"
              >
                <div className="flex items-center gap-3">
                  <CreditCard className="h-4 w-4 text-muted-foreground" />
                  <div>
                    <p className="text-sm font-medium">
                      {b.bank_name}{" "}
                      {b.is_primary && (
                        <span className="ml-1 text-[10px] text-primary">
                          {t("employee.bank.primary", "PRIMARY")}
                        </span>
                      )}
                    </p>
                    <p className="text-xs text-muted-foreground font-mono">
                      {b.account_number_masked}
                    </p>
                    {b.branch_name && (
                      <p className="text-xs text-muted-foreground">
                        {b.branch_name}
                      </p>
                    )}
                  </div>
                </div>
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => onDelete(b.public_id)}
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
              {t("employee.bank.add_title", "Add Bank Account")}
            </DialogTitle>
          </DialogHeader>
          <form
            onSubmit={submit(
              onAdd,
              t("employee.bank.add_failed", "Failed to add bank account"),
            )}
            className="space-y-4"
            noValidate
          >
            <FormErrorSummary message={rootError} />

            <FormField
              id="bank_name"
              label={t("employee.bank.bank_name", "Bank Name")}
              required
              error={fieldMessage(t, errors.bank_name?.message)}
            >
              <Input {...register("bank_name")} className="mt-1" />
            </FormField>

            <FormField
              id="bank_branch"
              label={t("employee.bank.branch_name", "Branch Name")}
              error={fieldMessage(t, errors.branch_name?.message)}
            >
              <Input {...register("branch_name")} className="mt-1" />
            </FormField>

            <FormField
              id="bank_account_number"
              label={t("employee.bank.account_number", "Account Number")}
              required
              error={fieldMessage(t, errors.account_number?.message)}
            >
              <Input
                {...register("account_number")}
                inputMode="numeric"
                className="mt-1 font-mono"
              />
            </FormField>

            <Controller
              name="is_primary"
              control={control}
              render={({ field }) => (
                <div className="flex items-start gap-2">
                  <Checkbox
                    id="bank_is_primary"
                    checked={field.value}
                    onCheckedChange={(checked) =>
                      field.onChange(checked === true)
                    }
                    aria-describedby="bank_is_primary_hint"
                    className="mt-0.5"
                  />
                  <div>
                    <label
                      htmlFor="bank_is_primary"
                      className="cursor-pointer text-sm font-medium"
                    >
                      {t(
                        "employee.bank.is_primary",
                        "Pay salary into this account",
                      )}
                    </label>
                    <p
                      id="bank_is_primary_hint"
                      className="text-xs text-muted-foreground"
                    >
                      {t(
                        "employee.bank.is_primary_hint",
                        "Payroll pays into one account. Choosing this one replaces the current salary account.",
                      )}
                    </p>
                  </div>
                </div>
              )}
            />

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
