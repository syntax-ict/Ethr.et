"use client";

import Link from "next/link";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { useT } from "@/lib/i18n/useT";
import type { Device } from "@/features/devices/api";

/**
 * What a terminal that only sends can honestly say about itself: when ETHR
 * last heard from it, or that it has not yet. Such a terminal is never probed,
 * so a Test button would only repeat this.
 */
export function DeviceHeardLine({
  device,
  showSetupLink = false,
}: {
  device: Pick<Device, "last_sync_at" | "public_id">;
  /** On the list card the setup steps are one click away, on the device page. */
  showSetupLink?: boolean;
}) {
  const { t } = useT();
  const { timeAgo } = useDateFormatters();

  if (!device.last_sync_at) {
    return (
      <div className="space-y-1">
        <p>{t("devices_page.never_heard")}</p>
        <p>{t("devices_page.never_heard_hint")}</p>
        {showSetupLink && (
          <Link
            href={`/devices/${device.public_id}`}
            className="font-medium text-foreground underline"
          >
            {t("devices_page.show_setup")}
          </Link>
        )}
      </div>
    );
  }

  return (
    <p>
      {t("devices_page.last_heard", undefined, {
        time: timeAgo(device.last_sync_at),
      })}
    </p>
  );
}

/** Why a pull failed, in the reader's language: the advice for a coded cause. */
const HINTED_CODES = [
  "sdk_port",
  "refused",
  "timeout",
  "dns",
  "auth",
  "http",
  "unreachable",
] as const;

/** The dictionary key for a cause's advice, or null when there is none to give. */
export function syncErrorHintKey(
  code: string | null | undefined,
): string | null {
  return code && (HINTED_CODES as readonly string[]).includes(code)
    ? `device_error.hint.${code}`
    : null;
}

export function SyncErrorHint({ code }: { code: string | null | undefined }) {
  const { t } = useT();
  const key = syncErrorHintKey(code);

  return key ? <>{t(key)}</> : null;
}
