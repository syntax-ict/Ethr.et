"use client";

import { Fingerprint, Loader2 } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { useEnrolEmployeeOnDevices } from "@/features/devices/api";
import { apiErrorMessage } from "@/lib/api/error-message";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useT } from "@/lib/i18n/useT";

/**
 * Send one employee to the devices of their branch that take commands.
 *
 * Hires, renames and badge changes already go out on their own; this is for a
 * terminal that was reset or replaced, or a person added before the device was
 * switched on. Their fingerprint or face is still captured at the terminal.
 */
export function EnrolOnDevicesButton({ employeeId }: { employeeId: string }) {
  const { t } = useT();
  const { hasPermission } = usePermissions();
  const enrol = useEnrolEmployeeOnDevices();

  if (!hasPermission("device.update")) return null;

  return (
    <Button
      variant="outline"
      size="sm"
      disabled={enrol.isPending}
      onClick={() =>
        enrol.mutate(employeeId, {
          onSuccess: ({ devices }) =>
            devices > 0
              ? toast.success(
                  t("device_commands.enrol_queued", undefined, { devices }),
                )
              : toast.info(t("device_commands.enrol_none")),
          onError: (e) =>
            toast.error(apiErrorMessage(e, t("device_commands.failed"))),
        })
      }
    >
      {enrol.isPending ? (
        <Loader2 className="mr-2 h-3 w-3 animate-spin" />
      ) : (
        <Fingerprint className="mr-2 h-3 w-3" />
      )}
      {t("device_commands.enrol")}
    </Button>
  );
}
