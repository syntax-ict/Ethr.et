"use client";

import { Clock, Loader2, Send, XCircle } from "lucide-react";
import { Controller } from "react-hook-form";
import { toast } from "sonner";
import { z } from "zod";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import { EmptyState } from "@/components/shared/empty-state";
import { RoleGate } from "@/components/shared/role-gate";
import { StatusBadge } from "@/components/shared/status-badge";
import { useConfirm } from "@/components/shared/use-confirm";
import { FormErrorSummary } from "@/components/patterns/FormErrorSummary";
import { FormField } from "@/components/patterns/FormField";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import {
  MAX_PLANNED_MINUTES,
  useCancelOvertimeRequest,
  useMyOvertimeRequests,
  useRequestOvertime,
  type OvertimeRequest,
} from "@/features/attendance/overtime-requests";
import { apiErrorMessage } from "@/lib/api/error-message";
import { fieldMessage, rules } from "@/lib/forms/rules";
import { useZodForm } from "@/lib/forms/use-zod-form";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { useT } from "@/lib/i18n/useT";

/** Half-hour steps up to the legal four hours. */
const DURATIONS = Array.from(
  { length: MAX_PLANNED_MINUTES / 30 },
  (_, i) => (i + 1) * 30,
);

function today(): string {
  const d = new Date();
  const pad = (n: number) => String(n).padStart(2, "0");
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

const schema = z.object({
  date: z
    .string()
    .regex(/^\d{4}-\d{2}-\d{2}$/, "validation.required")
    .refine((v) => v >= today(), "overtime_requests.date_past"),
  planned_minutes: z.string().min(1, "validation.required"),
  reason: rules.requiredText(1000),
});
type Values = z.infer<typeof schema>;

function hours(minutes: number): string {
  const h = minutes / 60;
  return Number.isInteger(h) ? String(h) : h.toFixed(1);
}

export default function OvertimeRequestsPage() {
  const { t } = useT();
  const { formatDate } = useDateFormatters();
  const requests = useMyOvertimeRequests();
  const ask = useRequestOvertime();
  const cancel = useCancelOvertimeRequest();
  const { requestConfirm, confirmDialog } = useConfirm();

  const {
    register,
    control,
    submit,
    reset,
    rootError,
    formState: { errors, isSubmitting },
  } = useZodForm<Values>({
    schema,
    defaultValues: { date: "", planned_minutes: "120", reason: "" },
  });

  async function onSubmit(values: Values) {
    await ask.mutateAsync({
      date: values.date,
      planned_minutes: Number(values.planned_minutes),
      reason: values.reason,
    });
    toast.success(
      t(
        "overtime_requests.sent",
        "Request sent. You will be told when it is decided.",
      ),
    );
    reset({ date: "", planned_minutes: "120", reason: "" });
  }

  async function confirmCancel(request: OvertimeRequest) {
    const ok = await requestConfirm({
      title: t(
        "overtime_requests.cancel_title",
        "Cancel your overtime request for :date?",
        { date: formatDate(request.date) },
      ),
      confirmLabel: t("overtime_requests.cancel", "Cancel request"),
      cancelLabel: t("overtime_requests.keep", "Keep it"),
      variant: "destructive",
    });
    if (!ok) return;

    cancel.mutate(request.public_id, {
      onSuccess: () =>
        toast.success(t("overtime_requests.cancelled", "Request cancelled")),
      onError: (err) =>
        toast.error(
          apiErrorMessage(
            err,
            t(
              "overtime_requests.cancel_failed",
              "The request could not be cancelled.",
            ),
          ),
        ),
    });
  }

  return (
    <RoleGate anyPermission={["requestOvertime"]}>
      <div className="space-y-6">
        <Card>
          <CardHeader>
            <CardTitle className="text-base">
              {t("overtime_requests.ask_title", "Ask to work overtime")}
            </CardTitle>
          </CardHeader>
          <CardContent>
            <p className="mb-4 text-sm text-muted-foreground">
              {t(
                "overtime_requests.ask_hint",
                "Ask before the day. Your supervisor or HR decides, and overtime you work is paid either way. At most 4 hours can be asked for one day, the legal limit.",
              )}
            </p>
            <form
              onSubmit={submit(
                onSubmit,
                t(
                  "overtime_requests.send_failed",
                  "The request could not be sent.",
                ),
              )}
              className="space-y-4"
              noValidate
            >
              <FormErrorSummary message={rootError} />
              <div className="grid gap-4 sm:grid-cols-2">
                <FormField
                  id="ot_date"
                  label={t("overtime_requests.date", "Day")}
                  required
                  error={fieldMessage(t, errors.date?.message)}
                >
                  <Input
                    {...register("date")}
                    type="date"
                    min={today()}
                    className="mt-1"
                  />
                </FormField>
                <Controller
                  name="planned_minutes"
                  control={control}
                  render={({ field }) => (
                    <FormField
                      id="ot_minutes"
                      label={t("overtime_requests.duration", "How long")}
                      required
                      error={fieldMessage(t, errors.planned_minutes?.message)}
                    >
                      {(controlProps) => (
                        <Select
                          value={field.value}
                          onValueChange={field.onChange}
                        >
                          <SelectTrigger {...controlProps} className="mt-1">
                            <SelectValue />
                          </SelectTrigger>
                          <SelectContent>
                            {DURATIONS.map((m) => (
                              <SelectItem key={m} value={String(m)}>
                                {t("overtime_requests.hours", ":hours hours", {
                                  hours: hours(m),
                                })}
                              </SelectItem>
                            ))}
                          </SelectContent>
                        </Select>
                      )}
                    </FormField>
                  )}
                />
              </div>
              <FormField
                id="ot_reason"
                label={t("overtime_requests.reason", "Why")}
                required
                error={fieldMessage(t, errors.reason?.message)}
              >
                <Textarea
                  {...register("reason")}
                  rows={3}
                  placeholder={t(
                    "overtime_requests.reason_placeholder",
                    "e.g. Month-end stock count",
                  )}
                  className="mt-1"
                />
              </FormField>
              <div className="flex justify-end">
                <Button type="submit" disabled={isSubmitting}>
                  {isSubmitting ? (
                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                  ) : (
                    <Send className="mr-2 h-4 w-4" />
                  )}
                  {t("overtime_requests.send", "Send request")}
                </Button>
              </div>
            </form>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle className="text-base">
              {t("overtime_requests.yours", "Your requests")}
            </CardTitle>
          </CardHeader>
          <CardContent>
            <QueryBoundary
              query={requests}
              empty={
                <EmptyState
                  icon={Clock}
                  title={t(
                    "overtime_requests.none",
                    "No overtime requests yet",
                  )}
                />
              }
            >
              {(page) => (
                <ul className="divide-y divide-border">
                  {page.data.map((r) => (
                    <li
                      key={r.public_id}
                      className="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between"
                    >
                      <div className="space-y-1">
                        <div className="flex flex-wrap items-center gap-2">
                          <span className="text-sm font-medium text-foreground">
                            {formatDate(r.date)}
                          </span>
                          <StatusBadge status={r.status} />
                        </div>
                        <p className="text-xs text-muted-foreground">
                          {t(
                            "overtime_requests.line",
                            ":hours hours: :reason",
                            {
                              hours: hours(r.planned_minutes),
                              reason: r.reason,
                            },
                          )}
                        </p>
                        {r.decision_note && (
                          <p className="text-xs text-muted-foreground">
                            {t("overtime_requests.note", "Reply: :note", {
                              note: r.decision_note,
                            })}
                          </p>
                        )}
                      </div>
                      {r.status === "pending" && (
                        <Button
                          variant="outline"
                          size="sm"
                          onClick={() => confirmCancel(r)}
                        >
                          <XCircle className="mr-2 h-4 w-4" />
                          {t("overtime_requests.cancel", "Cancel request")}
                        </Button>
                      )}
                    </li>
                  ))}
                </ul>
              )}
            </QueryBoundary>
          </CardContent>
        </Card>

        {confirmDialog}
      </div>
    </RoleGate>
  );
}
