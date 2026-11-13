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

const REJECTION_REASONS = [
  "token_retired",
  "token_truncated",
  "token_missing",
  "ip_not_allowed",
] as const;

/**
 * The terminal called and was refused, traced to this device by the server:
 * it sent a token this device had before, the start of its token, or no
 * token. Absent once the terminal has been heard from since.
 */
export function WebhookRejectedLine({
  rejected,
}: {
  rejected: Device["webhook_rejected"];
}) {
  const { t } = useT();
  const { timeAgo } = useDateFormatters();

  if (
    !rejected ||
    !(REJECTION_REASONS as readonly string[]).includes(rejected.reason)
  ) {
    return null;
  }

  return (
    <div
      role="status"
      className="mt-2 space-y-1 rounded-md border border-warning-edge bg-warning-soft p-3 text-sm text-warning-on-soft"
    >
      <p className="font-medium">
        {t("devices_page.rejected.title", undefined, {
          time: timeAgo(rejected.at),
        })}
      </p>
      <p>
        {t(`devices_page.rejected.${rejected.reason}`, undefined, {
          ip: rejected.ip ?? "?",
        })}
      </p>
    </div>
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

/**
 * Causes after which a Hikvision terminal may simply be on an office network
 * ETHR cannot reach, which only that vendor has a setting for.
 */
const OFFICE_NETWORK_CODES = [
  "sdk_port",
  "refused",
  "timeout",
  "auth",
  "unreachable",
];

/**
 * The dictionary keys of a cause's advice, in reading order: the
 * vendor-neutral hint, then what is true of a Hikvision terminal only (ETHR
 * signs in with Basic while many need Digest; the office network setting).
 * Empty when there is nothing to advise.
 */
export function syncErrorHintKeys(
  code: string | null | undefined,
  adapterType: string | null | undefined,
): string[] {
  if (!code || !(HINTED_CODES as readonly string[]).includes(code)) {
    return [];
  }
  const keys = [`device_error.hint.${code}`];
  if (adapterType === "hikvision") {
    if (code === "auth") keys.push("device_error.hikvision.digest");
    if (OFFICE_NETWORK_CODES.includes(code)) {
      keys.push("device_error.hikvision.office_network");
    }
  }
  return keys;
}

export function SyncErrorHint({
  code,
  adapterType,
}: {
  code: string | null | undefined;
  adapterType: string | null | undefined;
}) {
  const { t } = useT();
  const keys = syncErrorHintKeys(code, adapterType);

  return keys.length > 0 ? <>{keys.map((k) => t(k)).join(" ")}</> : null;
}
