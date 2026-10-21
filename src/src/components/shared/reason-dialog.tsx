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
import { useT } from "@/lib/i18n/useT";

interface ReasonDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  title: string;
  /** Who will read the reason, and why it matters. */
  description?: string;
  label: string;
  hint?: string;
  placeholder?: string;
  /** The verb that does it ("Reject"). */
  confirmLabel: string;
  loading?: boolean;
  /** The API's own limit for a rejection reason is 1000 characters. */
  maxLength?: number;
  onConfirm: (reason: string) => void;
}

/**
 * Asks why before a refusal is recorded.
 *
 * A refusal reaches the person refused, so "why" is the useful part. Three
 * screens collected a reason each with their own copy of this dialog, and the
 * team leave list collected none: it sent the literal English word
 * "Rejected" for every refusal (FRONTEND_REDESIGN_PLAN.md, problem 2).
 *
 * The reason is required and trimmed, and the confirm button stays off until
 * there is one. The field clears each time the dialog opens, so one person's
 * reason never pre-fills the next refusal.
 */
export function ReasonDialog({
  open,
  onOpenChange,
  title,
  description,
  label,
  hint,
  placeholder,
  confirmLabel,
  loading = false,
  maxLength = 1000,
  onConfirm,
}: ReasonDialogProps) {
  const { t } = useT();
  const id = useId();
  const [reason, setReason] = useState("");

  // Clear the field each time the dialog opens. Adjusted during render, not in
  // an effect, so the old text never paints for a frame first
  // (https://react.dev/learn/you-might-not-need-an-effect).
  const [wasOpen, setWasOpen] = useState(open);
  if (open !== wasOpen) {
    setWasOpen(open);
    if (open) setReason("");
  }

  const trimmed = reason.trim();

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        {...(description ? {} : { "aria-describedby": undefined })}
      >
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          {description && <DialogDescription>{description}</DialogDescription>}
        </DialogHeader>

        <FormField id={id} label={label} required hint={hint}>
          <Textarea
            rows={3}
            value={reason}
            maxLength={maxLength}
            onChange={(e) => setReason(e.target.value)}
            placeholder={placeholder}
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
            variant="destructive"
            onClick={() => onConfirm(trimmed)}
            disabled={loading || trimmed.length === 0}
          >
            {loading && (
              <Loader2
                className="mr-2 h-3 w-3 animate-spin"
                aria-hidden="true"
              />
            )}
            {confirmLabel}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
