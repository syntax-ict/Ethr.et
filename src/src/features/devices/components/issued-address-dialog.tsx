"use client";

import { Copy } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { PushSetup } from "@/features/devices/components/push-setup";
import { useT } from "@/lib/i18n/useT";

/**
 * The terminal's full address, shown once. The server keeps only a hash of the
 * token, so after this dialog closes nothing can show the address again; the
 * only way to see one is to regenerate it.
 */
export function IssuedAddressDialog({
  url,
  graceHours,
  onClose,
}: {
  /** The address to show; null keeps the dialog closed. */
  url: string | null;
  /** Set when the old address keeps working for a while (a terminal being moved). */
  graceHours?: number | null;
  onClose: () => void;
}) {
  const { t } = useT();

  async function copy() {
    try {
      await navigator.clipboard.writeText(url ?? "");
      toast.success(t("device_token.copied"));
    } catch {
      toast.error(t("devices_page.copy_failed"));
    }
  }

  return (
    <Dialog open={url !== null} onOpenChange={(open) => !open && onClose()}>
      {/* Done is the only way out: the address cannot be shown again. */}
      <DialogContent
        className="[&>button]:hidden"
        onInteractOutside={(event) => event.preventDefault()}
        onEscapeKeyDown={(event) => event.preventDefault()}
      >
        <DialogHeader>
          <DialogTitle>{t("device_token.issued_title")}</DialogTitle>
          <DialogDescription>
            {t("device_token.issued_intro")}
          </DialogDescription>
        </DialogHeader>
        <div className="flex items-start gap-2">
          <code
            data-testid="issued-address"
            className="flex-1 rounded border bg-muted/50 px-3 py-2 text-xs font-mono break-all"
          >
            {url}
          </code>
          <Button
            variant="outline"
            size="icon"
            className="h-8 w-8 shrink-0"
            aria-label={t("device_token.copy")}
            onClick={() => void copy()}
          >
            <Copy className="h-3 w-3" />
          </Button>
        </div>
        {url && <PushSetup url={url} />}
        {graceHours ? (
          <p className="text-xs text-muted-foreground">
            {t("device_token.grace_note", undefined, { hours: graceHours })}
          </p>
        ) : null}
        <DialogFooter>
          <Button onClick={onClose}>{t("device_token.done")}</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
