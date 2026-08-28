"use client";

import { useState } from "react";
import { Loader2, Plus, Trash2, CreditCard } from "lucide-react";
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
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import { useT } from "@/lib/i18n/useT";
import { useZodForm } from "@/lib/forms/use-zod-form";
import { rules, fieldMessage } from "@/lib/forms/rules";
import { z } from "zod";
import { toast } from "sonner";

interface BankDetail {
  public_id: string;
  bank_name: string;
  branch_name?: string;
  account_number: string;
  account_holder_name?: string;
  is_primary?: boolean;
}

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
  account_holder_name: rules.text(255),
  is_primary: z.boolean(),
});
type BankValues = z.infer<typeof bankSchema>;

const EMPTY_BANK: BankValues = {
  bank_name: "",
  branch_name: "",
  account_number: "",
  account_holder_name: "",
  is_primary: true,
};

export function BankDetailsTab({ employeeId }: { employeeId: string }) {
  const { t } = useT();
  const queryClient = useQueryClient();
  const [addOpen, setAddOpen] = useState(false);

  const {
    register,
    submit,
    reset,
    rootError,
    formState: { errors, isSubmitting },
  } = useZodForm<BankValues>({
    schema: bankSchema,
    defaultValues: EMPTY_BANK,
  });

  const { data, isLoading } = useQuery({
    queryKey: ["employee", employeeId, "bank-details"],
    queryFn: async () => {
      const { data } = await apiClient.get(
        `/employees/${employeeId}/bank-details`,
      );
      return data;
    },
  });

  const addBank = useMutation({
    mutationFn: async (values: BankValues) => {
      const { data } = await apiClient.post(
        `/employees/${employeeId}/bank-details`,
        values,
      );
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ["employee", employeeId, "bank-details"],
      });
      toast.success(t("employee.bank.added", "Bank details added"));
      setAddOpen(false);
      reset(EMPTY_BANK);
    },
  });

  const deleteBank = useMutation({
    mutationFn: async (id: string) => {
      await apiClient.delete(`/employees/${employeeId}/bank-details/${id}`);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ["employee", employeeId, "bank-details"],
      });
      toast.success(t("employee.bank.deleted", "Bank deleted"));
    },
  });

  const banks: BankDetail[] = data?.data ?? [];

  return (
    <Card>
      <CardHeader className="flex flex-row items-center justify-between">
        <CardTitle className="text-base">
          {t("employee.bank.title", "Bank Details")}
        </CardTitle>
        <Button size="sm" onClick={() => setAddOpen(true)}>
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
                      {b.account_number}
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
                  onClick={() => deleteBank.mutate(b.public_id)}
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
              (values) => addBank.mutateAsync(values),
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

            <FormField
              id="bank_holder"
              label={t("employee.bank.holder_name", "Account Holder Name")}
              error={fieldMessage(t, errors.account_holder_name?.message)}
            >
              <Input {...register("account_holder_name")} className="mt-1" />
            </FormField>

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
