"use client";

import { Loader2, Signal } from "lucide-react";
import { useState } from "react";
import { Button } from "@/components/ui/button";
import { useTestDeviceConnection } from "@/features/devices/api";
import { syncErrorHintKeys } from "@/features/devices/components/device-heard";
import { apiErrorMessage } from "@/lib/api/error-message";
import { useT } from "@/lib/i18n/useT";

/**
 * "Test connection" and its answer in words, for a terminal ETHR calls. One
 * piece for the dialog after registering and for the setup wizard, so the two
 * say the same thing about the same result.
 */
export function PullConnectionTest({
  devicePublicId,
  adapterType,
  onResult,
}: {
  devicePublicId: string;
  adapterType: string;
  /** Called with whether the terminal answered, for a step that waits on it. */
  onResult?: (online: boolean) => void;
}) {
  const { t } = useT();
  const test = useTestDeviceConnection();
  const [result, setResult] = useState<string | null>(null);

  function runTest() {
    setResult(null);
    test.mutate(devicePublicId, {
      onSuccess: ({ status, error_code }) => {
        onResult?.(status === "online");
        if (status === "online") {
          setResult(t("devices_page.online_responding"));
          return;
        }
        const hint = syncErrorHintKeys(error_code, adapterType)
          .map((k) => t(k))
          .join(" ");
        setResult(
          `${t("devices_page.device_status", "The device is :status", {
            status: t(`devices_page.${status}`, status),
          })}${hint ? ` ${hint}` : ""}`,
        );
      },
      onError: (err) => {
        onResult?.(false);
        setResult(apiErrorMessage(err, t("devices_page.test_failed")));
      },
    });
  }

  return (
    <div className="space-y-2">
      <Button
        variant="outline"
        size="sm"
        onClick={runTest}
        disabled={test.isPending}
      >
        {test.isPending ? (
          <Loader2 className="mr-2 h-4 w-4 animate-spin" />
        ) : (
          <Signal className="mr-2 h-4 w-4" />
        )}
        {t("devices_page.test_connection")}
      </Button>
      {result && (
        <p role="status" className="text-sm">
          {result}
        </p>
      )}
    </div>
  );
}
