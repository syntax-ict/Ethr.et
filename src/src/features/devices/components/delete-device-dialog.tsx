"use client";

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
import { useT } from "@/lib/i18n/useT";

/**
 * The one confirmation for deleting a device, on the list and on the device's
 * own page. It says what `DeviceController::destroy()` does: a soft delete.
 * The device leaves ETHR and ETHR stops reading from it, its attendance
 * records stay and stay linked to it, and the terminal itself is not touched.
 * There is no restore, so the last line says how to use the terminal again.
 * Two pages used to word this differently, and neither was accurate.
 */
export function DeleteDeviceDialog({
  deviceName,
  pending,
  onConfirm,
  onClose,
}: {
  /** The device to delete; null keeps the dialog closed. */
  deviceName: string | null;
  pending: boolean;
  onConfirm: () => void;
  onClose: () => void;
}) {
  const { t } = useT();
  const points = [
    t("device_delete.removed"),
    t("device_delete.stops_reading"),
    t("device_delete.records_kept"),
    t("device_delete.terminal_untouched"),
    t("device_delete.register_again"),
  ];

  return (
    <Dialog open={deviceName !== null} onOpenChange={(o) => !o && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t("devices_page.delete_device")}</DialogTitle>
          <DialogDescription>
            {t("devices_page.delete_confirm_prefix")}{" "}
            <strong>{deviceName}</strong>?
          </DialogDescription>
        </DialogHeader>
        <ul
          data-testid="device-delete-effects"
          className="list-disc space-y-1 pl-5 text-sm text-muted-foreground"
        >
          {points.map((text) => (
            <li key={text}>{text}</li>
          ))}
        </ul>
        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            {t("common.cancel")}
          </Button>
          <Button variant="destructive" onClick={onConfirm} disabled={pending}>
            {pending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
            {t("common.delete")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
