"use client";

import { AlertTriangle } from "lucide-react";
import type { Device } from "@/features/devices/api";
import {
  clockFinding,
  clockNeedsAttention,
  driftSize,
  type ClockFinding,
} from "@/features/devices/clock";
import { useT } from "@/lib/i18n/useT";

type T = ReturnType<typeof useT>["t"];

/** The drift as words: "6 hours", "1 minute", "7 years", plural chosen here. */
export function driftText(seconds: number, t: T): string {
  const { unit, count } = driftSize(seconds);
  return t(
    `device_clock.unit.${unit}_${count === 1 ? "one" : "many"}`,
    undefined,
    { count },
  );
}

/** The value shown on the clock card. Never a zero for a terminal that cannot be measured. */
export function clockValue(finding: ClockFinding, t: T): string {
  switch (finding.kind) {
    case "unknown":
      return "—";
    case "unmeasurable":
      return t("device_clock.not_measurable_short");
    case "in_step":
      return t("device_clock.in_step");
    default: {
      const drift = driftText(finding.seconds, t);
      return finding.seconds > 0
        ? t("device_clock.ahead_by", undefined, { drift })
        : t("device_clock.behind_by", undefined, { drift });
    }
  }
}

/**
 * What the clock reading says and what to do about it: a warning when the
 * terminal is more than two minutes out, the likely cause when the size gives
 * it away (six hours is Ethiopian time of day, years is the Ethiopian
 * calendar), and that the clock cannot be measured on a terminal that sends
 * no time with its heartbeat.
 */
export function DeviceClockNote({
  device,
}: {
  device: Pick<Device, "clock_offset_seconds" | "clock_measurable">;
}) {
  const { t } = useT();
  const finding = clockFinding(
    device.clock_offset_seconds ?? null,
    device.clock_measurable ?? null,
  );

  if (finding.kind === "unmeasurable") {
    return (
      <p className="text-sm text-muted-foreground">
        {t("device_clock.not_measurable")}
      </p>
    );
  }

  if (!clockNeedsAttention(finding) || !("seconds" in finding)) return null;

  const drift = driftText(finding.seconds, t);

  return (
    <div
      role="status"
      className="flex gap-2 rounded-lg border border-warning-edge bg-warning-soft p-3 text-sm text-warning-on-soft"
    >
      <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
      <div className="space-y-1">
        <p className="font-medium">
          {finding.seconds > 0
            ? t("device_clock.warning_ahead", undefined, { drift })
            : t("device_clock.warning_behind", undefined, { drift })}
        </p>
        {finding.kind === "ethiopian_time" && (
          <p>{t("device_clock.hint_ethiopian_time")}</p>
        )}
        {finding.kind === "ethiopian_calendar" && (
          <p>{t("device_clock.hint_ethiopian_calendar")}</p>
        )}
        {(finding.kind === "ahead" || finding.kind === "behind") && (
          <p>{t("device_clock.hint_set_clock")}</p>
        )}
      </div>
    </div>
  );
}
