"use client";

import { Badge } from "@/components/ui/badge";
import { CurrencyDisplay } from "@/components/shared/currency-display";
import { useCalendar } from "@/lib/calendar/calendar-context";
import { localizedPeriodLabel } from "@/lib/calendar/period-label";
import { useT } from "@/lib/i18n/useT";
import type { PayDifference } from "../api";

/** The month a difference belongs to, in the viewer's calendar and language. */
export function usePayDifferenceMonth() {
  const { calendar } = useCalendar();
  const { locale } = useT();
  return (d: PayDifference) =>
    localizedPeriodLabel(
      d.period_label,
      d.period_start,
      d.period_end,
      calendar,
      locale,
    );
}

/** Underpaid, overpaid, or still being worked out. */
export function KindLabel({ kind }: { kind: string | null }) {
  const { t } = useT();
  if (kind === "underpaid") return <>{t("pay_differences.kind_underpaid")}</>;
  if (kind === "overpaid") return <>{t("pay_differences.kind_overpaid")}</>;
  return <>{t("pay_differences.kind_pending")}</>;
}

/** A signed amount, or a dash while the amount is still being worked out. */
export function SignedMoney({
  cents,
  className,
}: {
  cents: number | null;
  className?: string;
}) {
  if (cents === null) return <span className={className}>-</span>;
  return <CurrencyDisplay cents={cents} className={className} />;
}

/** The two flags a figure can carry, each with a short plain explanation. */
export function PayDifferenceFlags({
  difference,
}: {
  difference: PayDifference;
}) {
  const { t } = useT();
  if (!difference.trail_rebuilt_at_release && !difference.net_is_estimate) {
    return null;
  }
  return (
    <ul className="mt-1 space-y-0.5 text-xs text-muted-foreground">
      {difference.trail_rebuilt_at_release && (
        <li>{t("pay_differences.flag_rebuilt")}</li>
      )}
      {difference.net_is_estimate && (
        <li>{t("pay_differences.flag_estimate")}</li>
      )}
    </ul>
  );
}

export function PayDifferenceStatusBadge({ status }: { status: string }) {
  const { t } = useT();
  const labels: Record<string, string> = {
    pending: t("pay_differences.status_pending"),
    open: t("pay_differences.status_open"),
    settled: t("pay_differences.status_settled"),
    dismissed: t("pay_differences.status_dismissed"),
    superseded: t("pay_differences.status_superseded"),
  };
  return <Badge variant="outline">{labels[status] ?? status}</Badge>;
}
