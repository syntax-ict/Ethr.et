"use client";

import { useState, type ReactNode } from "react";
import { AlertTriangle, Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { useT } from "@/lib/i18n/useT";

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  title: string;
  description: string;
  /** What the change does, in one sentence, shown before the reason. */
  consequence?: string;
  confirmLabel: string;
  destructive?: boolean;
  pending: boolean;
  /** Field errors from a 422, keyed by field. */
  errors: Record<string, string>;
  onSubmit: (reason: string) => void;
  /** Fields of the change itself (a state, a mode, an expiry), above the reason. */
  children?: ReactNode;
};

/**
 * Every feature switch asks for a reason, which the server records in the
 * audit log with the admin's name (owner decision D3: a reason, not a second
 * authenticator code). Shaped like the organisation delete dialog.
 */
export function FeatureReasonDialog({
  open,
  onOpenChange,
  title,
  description,
  consequence,
  confirmLabel,
  destructive = false,
  pending,
  errors,
  onSubmit,
  children,
}: Props) {
  const { t } = useT();
  const [reason, setReason] = useState("");
  const ready = reason.trim().length >= 3;

  function close(next: boolean) {
    if (pending) return;
    onOpenChange(next);
    if (!next) setReason("");
  }

  function submit(e: React.FormEvent) {
    e.preventDefault();
    if (ready) onSubmit(reason.trim());
  }

  return (
    <Dialog open={open} onOpenChange={close}>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
        <form onSubmit={submit} className="space-y-4">
          <DialogHeader>
            <DialogTitle>{title}</DialogTitle>
            <DialogDescription>{description}</DialogDescription>
          </DialogHeader>

          {children}

          {consequence && (
            <div
              className={
                destructive
                  ? "rounded-lg border border-destructive/30 bg-destructive/5 p-3"
                  : "rounded-lg border border-border bg-muted p-3"
              }
            >
              <div className="flex items-start gap-2">
                <AlertTriangle
                  className={
                    destructive
                      ? "mt-0.5 h-4 w-4 shrink-0 text-destructive"
                      : "mt-0.5 h-4 w-4 shrink-0 text-muted-foreground"
                  }
                  aria-hidden
                />
                <p className="text-sm text-foreground">{consequence}</p>
              </div>
            </div>
          )}

          <div className="space-y-1.5">
            <Label htmlFor="feature-change-reason">
              {t("admin_features.reason_label", "Reason")}
            </Label>
            <Textarea
              id="feature-change-reason"
              rows={2}
              maxLength={500}
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              aria-invalid={errors.reason ? true : undefined}
            />
            <p className="text-xs text-muted-foreground">
              {t(
                "admin_features.reason_hint",
                "Recorded in the audit log with your name.",
              )}
            </p>
            {errors.reason && (
              <p className="text-xs text-destructive">{errors.reason}</p>
            )}
          </div>

          <DialogFooter>
            <Button
              type="button"
              variant="outline"
              onClick={() => close(false)}
              disabled={pending}
            >
              {t("admin_features.cancel", "Cancel")}
            </Button>
            <Button
              type="submit"
              variant={destructive ? "destructive" : "default"}
              disabled={!ready || pending}
            >
              {pending && (
                <Loader2 className="mr-1.5 h-4 w-4 animate-spin" aria-hidden />
              )}
              {confirmLabel}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

/** A 422's field errors, first message per field; empty for any other error. */
export function fieldErrorsOf(err: unknown): Record<string, string> {
  const data = (
    err as { response?: { data?: { errors?: Record<string, string[]> } } }
  ).response?.data;
  const errors: Record<string, string> = {};
  for (const [field, messages] of Object.entries(data?.errors ?? {})) {
    if (messages[0]) errors[field] = messages[0];
  }
  return errors;
}
