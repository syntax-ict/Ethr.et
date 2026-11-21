"use client";

import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { apiErrorMessage } from "@/lib/api/error-message";
import { useAllowDeviceAddress } from "@/features/devices/api";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useT } from "@/lib/i18n/useT";

/**
 * "Allow this address", beside a refusal that names the address a terminal
 * called from. It adds that one address to the device's allowlist, so the fix
 * is a click and not a typed number. Only for someone who may change the
 * device; a view-only user sees the refusal and not the button.
 */
export function AllowAddressButton({
  devicePublicId,
  ip,
}: {
  devicePublicId: string;
  ip: string | null | undefined;
}) {
  const { t } = useT();
  const { hasPermission } = usePermissions();
  const allow = useAllowDeviceAddress();

  if (!ip || !hasPermission("device.update")) return null;

  return (
    <Button
      type="button"
      size="sm"
      variant="outline"
      disabled={allow.isPending}
      onClick={() =>
        allow.mutate(
          { publicId: devicePublicId, ip },
          {
            onSuccess: () =>
              toast.success(t("device_allow.added", undefined, { ip })),
            onError: (error) =>
              toast.error(apiErrorMessage(error, t("device_allow.failed"))),
          },
        )
      }
    >
      {t("device_allow.button", undefined, { ip })}
    </Button>
  );
}
