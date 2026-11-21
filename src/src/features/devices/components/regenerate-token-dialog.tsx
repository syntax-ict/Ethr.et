"use client";

import { useState } from "react";
import { toast } from "sonner";
import { Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { apiErrorMessage } from "@/lib/api/error-message";
import {
  useRegenerateDeviceToken,
  type TokenReason,
} from "@/features/devices/api";
import { IssuedAddressDialog } from "@/features/devices/components/issued-address-dialog";
import { useT } from "@/lib/i18n/useT";

/**
 * Replacing a terminal's address. It asks why, because the two reasons want
 * opposite things: an address that was lost must stop working now, and a
 * terminal being moved needs its old address for a day while it is reset.
 * "Lost" is the default, as it is on the server when no reason arrives.
 * The new address is then shown once, in the dialog this renders.
 */
export function RegenerateTokenDialog({
  devicePublicId,
  open,
  onOpenChange,
}: {
  devicePublicId: string;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const { t } = useT();
  const regenerate = useRegenerateDeviceToken();
  const [reason, setReason] = useState<TokenReason>("lost");
  const [issued, setIssued] = useState<{
    url: string;
    graceHours: number | null;
  } | null>(null);

  function submit() {
    regenerate.mutate(
      { publicId: devicePublicId, reason },
      {
        onSuccess: (result) => {
          toast.success(t("device_detail_page.token_regenerated"));
          onOpenChange(false);
          setReason("lost");
          if (result.webhook_url) {
            setIssued({
              url: result.webhook_url,
              graceHours: reason === "moving" ? 24 : null,
            });
          }
        },
        onError: (err) => {
          toast.error(
            apiErrorMessage(err, t("device_detail_page.regenerate_failed")),
          );
          onOpenChange(false);
        },
      },
    );
  }

  const choices: { value: TokenReason; label: string }[] = [
    { value: "lost", label: t("device_token.reason_lost") },
    { value: "moving", label: t("device_token.reason_moving") },
  ];

  return (
    <>
      <Dialog open={open} onOpenChange={onOpenChange}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t("device_token.regenerate_title")}</DialogTitle>
            <DialogDescription>
              {t("device_token.regenerate_desc")}
            </DialogDescription>
          </DialogHeader>
          <fieldset className="space-y-2">
            <legend className="sr-only">
              {t("device_token.regenerate_title")}
            </legend>
            {choices.map((choice) => (
              <label
                key={choice.value}
                className="flex cursor-pointer items-start gap-2 rounded-md border p-3 text-sm"
              >
                <input
                  type="radio"
                  name="token-reason"
                  className="mt-1"
                  value={choice.value}
                  checked={reason === choice.value}
                  onChange={() => setReason(choice.value)}
                />
                <span>{choice.label}</span>
              </label>
            ))}
          </fieldset>
          <DialogFooter>
            <Button
              variant="outline"
              onClick={() => onOpenChange(false)}
              disabled={regenerate.isPending}
            >
              {t("common.cancel", "Cancel")}
            </Button>
            <Button
              variant="destructive"
              onClick={submit}
              disabled={regenerate.isPending}
            >
              {regenerate.isPending && (
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
              )}
              {t("device_detail_page.regenerate_token")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
      <IssuedAddressDialog
        url={issued?.url ?? null}
        graceHours={issued?.graceHours}
        onClose={() => setIssued(null)}
      />
    </>
  );
}
