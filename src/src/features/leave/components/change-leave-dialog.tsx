"use client";

import { useId, useState } from "react";
import { Loader2 } from "lucide-react";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
import { Textarea } from "@/components/ui/textarea";
import { FormField } from "@/components/patterns/FormField";
import { DualCalendarDateInput } from "@/components/shared/dual-calendar-date-input";
import { useT } from "@/lib/i18n/useT";

/** The day before a `YYYY-MM-DD` date, as `YYYY-MM-DD`. */
export function dayBefore(date: string): string {
  const d = new Date(`${date}T00:00:00Z`);
  d.setUTCDate(d.getUTCDate() - 1);
  return d.toISOString().slice(0, 10);
}

interface ChangeLeaveDialogProps {
  /** The approved request being changed; null closes the dialog. */
  request: {
    start_date: string;
    end_date: string;
    employee?: { name?: string | null } | null;
  } | null;
  onOpenChange: (open: boolean) => void;
  loading?: boolean;
  /** `endDate` null cancels the whole leave. */
  onConfirm: (change: { endDate: string | null; reason: string }) => void;
}

/**
 * HR cancelling approved leave, or ending it early (A-Z review E-02). The
 * days it no longer takes go back to the person's balance, and they are told
 * why, so the reason is required. A new end date runs from the first day to
 * the day before the current last one; the API refuses anything else.
 */
export function ChangeLeaveDialog({
  request,
  onOpenChange,
  loading = false,
  onConfirm,
}: ChangeLeaveDialogProps) {
  const { t } = useT();
  const id = useId();
  const open = request !== null;
  const [mode, setMode] = useState<"cancel" | "shorten">("shorten");
  const [endDate, setEndDate] = useState("");
  const [reason, setReason] = useState("");

  // A fresh form for each request, adjusted during render as ReasonDialog does.
  const [shownFor, setShownFor] = useState(request);
  if (request !== shownFor) {
    setShownFor(request);
    if (request) {
      setMode("shorten");
      setEndDate("");
      setReason("");
    }
  }

  const trimmed = reason.trim();
  const latestEnd = request ? dayBefore(request.end_date) : "";
  const endValid =
    mode === "cancel" ||
    (request !== null &&
      endDate !== "" &&
      endDate >= request.start_date &&
      endDate <= latestEnd);
  const canSubmit = !loading && trimmed.length > 0 && endValid;

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>
            {t("leave_page.change_title", "Change approved leave")}
          </DialogTitle>
          <DialogDescription>
            {t(
              "leave_page.change_description",
              "The days this leave no longer takes go back to :name's balance, and they are told why.",
              { name: request?.employee?.name ?? "" },
            )}
          </DialogDescription>
        </DialogHeader>

        <fieldset className="space-y-2">
          <legend className="sr-only">
            {t("leave_page.change_how", "How to change it")}
          </legend>
          <label className="flex items-center gap-2 text-sm">
            <input
              type="radio"
              name={`${id}-mode`}
              checked={mode === "shorten"}
              onChange={() => setMode("shorten")}
            />
            {t("leave_page.change_shorten", "End it early")}
          </label>
          <label className="flex items-center gap-2 text-sm">
            <input
              type="radio"
              name={`${id}-mode`}
              checked={mode === "cancel"}
              onChange={() => setMode("cancel")}
            />
            {t("leave_page.change_cancel", "Cancel the whole leave")}
          </label>
        </fieldset>

        {mode === "shorten" && request && (
          <FormField
            id={`${id}-end`}
            label={t("leave_page.change_new_end", "New last day of leave")}
            required
          >
            <DualCalendarDateInput
              id={`${id}-end`}
              value={endDate}
              onChange={setEndDate}
              min={request.start_date}
              max={latestEnd}
              required
            />
          </FormField>
        )}

        <FormField
          id={`${id}-reason`}
          label={t("leave_page.change_reason", "Reason")}
          required
          hint={t("approvals.reject_reason_hint", "Visible to the employee.")}
        >
          <Textarea
            id={`${id}-reason`}
            rows={3}
            value={reason}
            maxLength={1000}
            onChange={(e) => setReason(e.target.value)}
            placeholder={t(
              "leave_page.change_reason_placeholder",
              "e.g. Came back to work early.",
            )}
          />
        </FormField>

        <DialogFooter>
          <Button
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={loading}
          >
            {t("common.cancel", "Cancel")}
          </Button>
          <Button
            variant={mode === "cancel" ? "destructive" : "default"}
            onClick={() =>
              onConfirm({
                endDate: mode === "cancel" ? null : endDate,
                reason: trimmed,
              })
            }
            disabled={!canSubmit}
          >
            {loading && (
              <Loader2
                className="mr-2 h-3 w-3 animate-spin"
                aria-hidden="true"
              />
            )}
            {mode === "cancel"
              ? t("leave_page.change_confirm_cancel", "Cancel leave")
              : t("leave_page.change_confirm_shorten", "Save new end date")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
