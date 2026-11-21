"use client";

import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import type { Device } from "@/features/devices/api";
import { useT } from "@/lib/i18n/useT";

type Today = NonNullable<Device["today"]>;

/**
 * What this terminal sent today, in the organisation's day. Received is every
 * punch ETHR accepted, and the other figures say where each went, so a
 * terminal that sent forty punches and recorded two is seen as that.
 */
export function DeviceTodayCard({ today }: { today: Device["today"] }) {
  const { t } = useT();
  if (!today) return null;

  const cells: { key: keyof Today; warn?: boolean }[] = [
    { key: "received" },
    { key: "recorded" },
    { key: "held", warn: true },
    { key: "duplicate" },
    { key: "quarantined", warn: true },
  ];

  return (
    <Card>
      <CardHeader className="pb-3">
        <CardTitle className="text-sm">{t("device_today.title")}</CardTitle>
      </CardHeader>
      <CardContent className="space-y-3">
        <dl className="grid grid-cols-2 gap-4 sm:grid-cols-5">
          {cells.map(({ key, warn }) => {
            const value = Number(today[key] ?? 0);
            return (
              <div key={key}>
                <dt className="text-xs text-muted-foreground">
                  {t(`device_today.${key}`)}
                </dt>
                <dd
                  className={`text-xl font-bold tabular-nums ${warn && value > 0 ? "text-warning" : "text-foreground"}`}
                >
                  {value.toLocaleString()}
                </dd>
              </div>
            );
          })}
        </dl>
        {Number(today.waiting ?? 0) > 0 && (
          <p className="text-xs text-muted-foreground">
            {Number(today.waiting) === 1
              ? t("device_today.waiting_one")
              : t("device_today.waiting_many", undefined, {
                  count: today.waiting,
                })}
          </p>
        )}
        <p className="text-xs text-muted-foreground">
          {t("device_today.hint")}
        </p>
      </CardContent>
    </Card>
  );
}

/** One line for a table cell: the same figures, short. */
export function DeviceTodayLine({ today }: { today: Device["today"] }) {
  const { t } = useT();
  if (!today) return <span>—</span>;

  return (
    <span className="tabular-nums">
      {t("device_today.line", undefined, {
        received: today.received,
        recorded: today.recorded,
        held: today.held,
        quarantined: today.quarantined,
      })}
    </span>
  );
}
