import type { ReactNode } from "react";
import { StatCard, type StatTone } from "@/components/dashboard/stat-card";
import { ChartCard as KitChartCard } from "@/components/dashboard/chart-card";
import { CHART_PALETTE } from "@/components/dashboard/chart-theme";
import { formatETB, formatETBCompact } from "@/lib/utils/currency";

/**
 * The Analytics tabs' building blocks, drawn by the shared dashboard kit so
 * these charts and tiles match the home dashboard and the platform console.
 *
 * Until 2026-10-12 this file had its own palette (status and brand tokens
 * mixed), its own KPI tile, and tone classes built from arbitrary values —
 * including gold text, which the design tokens forbid (2.08:1 on white).
 */
export const CHART_COLORS = [...CHART_PALETTE];

const TONE: Record<string, StatTone> = {
  blue: "primary",
  green: "success",
  amber: "warning",
  purple: "info",
};

export function ChartCard({
  title,
  subtitle,
  action,
  children,
}: {
  title: string;
  subtitle?: string;
  action?: ReactNode;
  children: ReactNode;
}) {
  return (
    <KitChartCard title={title} description={subtitle} action={action}>
      {children}
    </KitChartCard>
  );
}

export function KpiBox({
  icon,
  title,
  value,
  sub,
  color,
}: {
  icon: React.ComponentType<{ className?: string }>;
  title: string;
  value: string | number;
  sub: string;
  color: string;
}) {
  return (
    <StatCard
      icon={icon}
      tone={TONE[color] ?? "primary"}
      label={title}
      value={value}
      hint={sub}
    />
  );
}

export function KpiBoxCurrency({
  icon,
  title,
  cents,
  sub,
  color,
}: {
  icon: React.ComponentType<{ className?: string }>;
  title: string;
  cents: number;
  sub?: string;
  color: string;
}) {
  return (
    <StatCard
      icon={icon}
      tone={TONE[color] ?? "primary"}
      label={title}
      // The tile shows `2.59M ETB`; the exact figure is the tooltip, so
      // nothing is lost by fitting it.
      value={<span title={formatETB(cents)}>{formatETBCompact(cents)}</span>}
      hint={sub}
    />
  );
}

export function Metric({
  label,
  value,
  highlight,
}: {
  label: string;
  value: string | number;
  highlight?: boolean;
}) {
  return (
    <div className="min-w-0 flex-1 px-4 text-center">
      <p className="text-sm text-muted-foreground">{label}</p>
      <p
        className={`mt-1 text-3xl font-semibold tracking-tight tabular-nums ${highlight ? "text-primary" : "text-foreground"}`}
      >
        {value}
      </p>
    </div>
  );
}
