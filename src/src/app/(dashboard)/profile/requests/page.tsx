"use client";

import { forwardRef, useState } from "react";
import { useForm, Controller } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import {
  Clock,
  Send,
  Loader2,
  Undo2,
  CheckCircle2,
  XCircle,
  ShieldQuestion,
} from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { DualCalendarDateInput } from "@/components/shared/dual-calendar-date-input";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import { ConfirmDialog } from "@/components/shared/confirm-dialog";
import {
  useMyProfile,
  useUpdateProfile,
  useWithdrawProfileUpdate,
  type ProfileResponse,
  type ProfileUpdateRequest,
} from "@/features/profile/api";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";

const requestSchema = z.object({
  name: z.string().max(255).optional(),
  name_am: z.string().max(255).optional(),
  date_of_birth: z.string().optional(),
  tin: z.string().max(50).optional(),
  bank_name: z.string().max(100).optional(),
  bank_account_number: z.string().max(50).optional(),
});
type RequestForm = z.infer<typeof requestSchema>;

export default function ProfileRequestsPage() {
  const query = useMyProfile();

  return (
    <QueryBoundary query={query}>
      {(profile) => <ProfileRequests profile={profile} />}
    </QueryBoundary>
  );
}

function ProfileRequests({ profile }: { profile: ProfileResponse }) {
  const { t } = useT();
  const employee = profile.employee;
  const submit = useUpdateProfile();
  const pending = profile.pending_updates ?? [];
  const recent = profile.recent_updates ?? [];

  const [emptySubmit, setEmptySubmit] = useState(false);
  const { register, handleSubmit, reset, control } = useForm<RequestForm>({
    resolver: zodResolver(requestSchema),
    defaultValues: {
      name: "",
      name_am: "",
      date_of_birth: "",
      tin: "",
      bank_name: "",
      bank_account_number: "",
    },
  });

  async function onSubmit(values: RequestForm) {
    const payload = Object.fromEntries(
      Object.entries(values).filter(([, v]) => (v ?? "") !== ""),
    );

    if (Object.keys(payload).length === 0) {
      setEmptySubmit(true);
      return;
    }
    setEmptySubmit(false);

    try {
      const result = await submit.mutateAsync(payload);

      if (result.pending_approval) {
        const fields = result.pending_approval.fields
          .map((f) => f.replace(/_/g, " "))
          .join(", ");
        toast.success(
          `${t("profile.request_submitted", "Sent to HR for review.")} (${fields})`,
        );
      } else {
        // Everything submitted already matched what is on record.
        toast.info(
          t("profile.request_no_change", "Nothing changed — no request sent."),
        );
      }
      reset();
    } catch {
      toast.error(
        t(
          "profile.update_failed",
          "Failed to update profile. Please try again.",
        ),
      );
    }
  }

  if (!employee) {
    return (
      <Card>
        <CardContent className="py-10 text-center text-sm text-muted-foreground">
          {t(
            "profile.no_employee_record",
            "Your account is not linked to an employee record yet. Ask your HR administrator to link it.",
          )}
        </CardContent>
      </Card>
    );
  }

  return (
    <div className="space-y-6">
      {pending.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2 text-base">
              <Clock className="h-4 w-4 text-status-warning" />
              {t("profile.pending_updates_title", "Changes awaiting HR review")}
            </CardTitle>
          </CardHeader>
          <CardContent className="space-y-3">
            {pending.map((request) => (
              <PendingRow key={request.public_id} request={request} />
            ))}
          </CardContent>
        </Card>
      )}

      <form onSubmit={handleSubmit(onSubmit)}>
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2 text-base">
              <ShieldQuestion className="h-4 w-4 text-muted-foreground" />
              {t("profile.request_a_change", "Request a change")}
            </CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            <p className="text-sm text-muted-foreground">
              {t(
                "profile.gated_explainer",
                "These fields affect payroll and legal records, so HR reviews them before they take effect. Leave a field blank to keep it as it is.",
              )}
            </p>

            <div className="grid gap-4 sm:grid-cols-2">
              <Field
                id="name"
                label={t("profile.legal_name", "Legal name")}
                current={employee.name}
                {...register("name")}
              />
              <Field
                id="name_am"
                label={t("profile.name_amharic", "Name (Amharic)")}
                current={employee.name_am}
                {...register("name_am")}
              />
              <Controller
                control={control}
                name="date_of_birth"
                render={({ field }) => (
                  <div className="space-y-2">
                    <Label htmlFor="date_of_birth">
                      {t("employee.detail.date_of_birth", "Date of birth")}
                    </Label>
                    <p className="text-xs text-muted-foreground">
                      {t("profile.on_record", "On record")}:{" "}
                      <span className="font-medium text-foreground">
                        {employee.date_of_birth ||
                          t("common.not_set", "Not set")}
                      </span>
                    </p>
                    <DualCalendarDateInput
                      id="date_of_birth"
                      value={field.value ?? ""}
                      onChange={field.onChange}
                    />
                  </div>
                )}
              />
              <Field
                id="tin"
                label={t("profile.tin", "TIN")}
                current={employee.tin_masked}
                {...register("tin")}
              />
              <Field
                id="bank_name"
                label={t("profile.bank_name", "Bank name")}
                current={profile.bank_details?.[0]?.bank_name ?? null}
                {...register("bank_name")}
              />
              <Field
                id="bank_account_number"
                label={t("profile.bank_account", "Bank account number")}
                current={
                  profile.bank_details?.[0]?.account_number_masked ?? null
                }
                {...register("bank_account_number")}
              />
            </div>

            {emptySubmit && (
              <p className="text-xs text-destructive">
                {t("profile.request_empty", "Fill in at least one field.")}
              </p>
            )}

            <div className="flex justify-end">
              <Button type="submit" size="sm" disabled={submit.isPending}>
                {submit.isPending ? (
                  <Loader2 className="mr-1 h-3 w-3 animate-spin" />
                ) : (
                  <Send className="mr-1 h-3 w-3" />
                )}
                {t("profile.send_for_review", "Send for review")}
              </Button>
            </div>
          </CardContent>
        </Card>
      </form>

      {recent.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle className="text-base">
              {t("profile.recent_decisions", "Recent decisions")}
            </CardTitle>
          </CardHeader>
          <CardContent className="space-y-3">
            {recent.map((request) => (
              <DecidedRow key={request.public_id} request={request} />
            ))}
          </CardContent>
        </Card>
      )}
    </div>
  );
}

function PendingRow({ request }: { request: ProfileUpdateRequest }) {
  const { t } = useT();
  const [confirming, setConfirming] = useState(false);
  const withdraw = useWithdrawProfileUpdate();

  async function onWithdraw() {
    try {
      await withdraw.mutateAsync(request.public_id);
      toast.success(t("profile.request_withdrawn", "Request withdrawn."));
    } catch {
      toast.error(t("common.action_failed", "That didn't work. Try again."));
    } finally {
      setConfirming(false);
    }
  }

  return (
    <div className="flex flex-col gap-2 rounded-md border border-border-default bg-surface-secondary p-3 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <p className="text-sm font-medium capitalize text-foreground">
          {request.field_name.replace(/_/g, " ")}
        </p>
        <p className="text-sm text-muted-foreground">
          <span className="line-through">
            {request.old_value ?? t("common.not_set", "Not set")}
          </span>{" "}
          → <span className="font-medium">{request.new_value}</span>
        </p>
      </div>
      <div className="flex items-center gap-2">
        <Badge className="w-fit bg-warning-soft text-warning-on-soft border-0">
          {t("profile.awaiting_review", "Awaiting review")}
        </Badge>
        <Button
          size="sm"
          variant="outline"
          disabled={withdraw.isPending}
          onClick={() => setConfirming(true)}
        >
          <Undo2 className="mr-1 h-3 w-3" />
          {t("profile.withdraw", "Withdraw")}
        </Button>
      </div>

      <ConfirmDialog
        open={confirming}
        onOpenChange={setConfirming}
        title={t("profile.withdraw_title", "Withdraw this request?")}
        description={t(
          "profile.withdraw_hint",
          "HR will no longer see it. You can submit the change again later.",
        )}
        confirmLabel={t("profile.withdraw", "Withdraw")}
        loading={withdraw.isPending}
        onConfirm={onWithdraw}
      />
    </div>
  );
}

function DecidedRow({ request }: { request: ProfileUpdateRequest }) {
  const { t } = useT();
  const approved = request.status === "approved";
  const withdrawn = request.status === "withdrawn";

  return (
    <div className="flex flex-col gap-1 rounded-md border border-border-default p-3 sm:flex-row sm:items-start sm:justify-between">
      <div>
        <p className="text-sm font-medium capitalize text-foreground">
          {request.field_name.replace(/_/g, " ")}
        </p>
        <p className="text-sm text-muted-foreground">{request.new_value}</p>
        {request.review_notes && (
          <p className="mt-1 text-xs text-muted-foreground">
            {request.review_notes}
          </p>
        )}
      </div>
      <Badge
        variant="outline"
        className={
          approved
            ? "border-0 bg-success-soft text-success-on-soft"
            : withdrawn
              ? "border-0 bg-muted text-muted-foreground"
              : "border-0 bg-destructive/10 text-destructive"
        }
      >
        {approved ? (
          <CheckCircle2 className="mr-1 h-3 w-3" />
        ) : withdrawn ? (
          <Undo2 className="mr-1 h-3 w-3" />
        ) : (
          <XCircle className="mr-1 h-3 w-3" />
        )}
        {t(`profile.status.${request.status}`, request.status)}
      </Badge>
    </div>
  );
}

/**
 * A gated field: what is on record today, plus an input for what it should be.
 * Showing the current value is the point — an employee cannot tell whether their
 * TIN needs correcting if the form is blank.
 */
const Field = forwardRef<
  HTMLInputElement,
  {
    id: string;
    label: string;
    current: string | null | undefined;
  } & React.ComponentPropsWithoutRef<typeof Input>
>(function Field({ id, label, current, type = "text", ...inputProps }, ref) {
  const { t } = useT();

  return (
    <div className="space-y-2">
      <Label htmlFor={id}>{label}</Label>
      <p className="text-xs text-muted-foreground">
        {t("profile.on_record", "On record")}:{" "}
        <span className="font-medium text-foreground">
          {current || t("common.not_set", "Not set")}
        </span>
      </p>
      <Input
        id={id}
        type={type}
        ref={ref}
        placeholder={t("profile.new_value", "New value")}
        {...inputProps}
      />
    </div>
  );
});
