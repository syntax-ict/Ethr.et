import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { CurrencyDisplay } from "@/components/shared/currency-display";

export const CHART_COLORS = [
  "var(--interactive-primary)",
  "var(--status-success)",
  "var(--brand-accent)",
  "var(--status-error)",
  "var(--status-info)",
  "var(--interactive-hover)",
  "var(--status-warning)",
  "var(--border-strong)",
];

const toneMap: Record<string, string> = {
  blue: "bg-primary-soft text-primary-on-soft",
  green: "bg-[var(--status-success)]/10 text-[var(--status-success)]",
  amber: "bg-[var(--brand-accent)]/10 text-[var(--brand-accent)]",
  purple: "bg-[var(--status-info)]/10 text-[var(--status-info)]",
};

export function ChartCard({
  title,
  subtitle,
  children,
}: {
  title: string;
  subtitle?: string;
  children: React.ReactNode;
}) {
  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">{title}</CardTitle>
        {subtitle && (
          <p className="mt-1 text-xs text-muted-foreground">{subtitle}</p>
        )}
      </CardHeader>
      <CardContent>{children}</CardContent>
    </Card>
  );
}

export function KpiBox({
  icon: Icon,
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
    <Card>
      <CardContent className="flex items-center gap-3 p-4">
        <div
          className={`flex h-10 w-10 items-center justify-center rounded-xl ${toneMap[color]}`}
        >
          <Icon className="h-5 w-5" />
        </div>
        <div>
          <p className="text-xs text-muted-foreground">{title}</p>
          <p className="text-xl font-bold text-foreground">{value}</p>
          <p className="text-[10px] text-muted-foreground">{sub}</p>
        </div>
      </CardContent>
    </Card>
  );
}

export function KpiBoxCurrency({
  icon: Icon,
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
    <Card>
      <CardContent className="flex items-center gap-3 p-4">
        <div
          className={`flex h-10 w-10 items-center justify-center rounded-xl ${toneMap[color]}`}
        >
          <Icon className="h-5 w-5" />
        </div>
        <div>
          <p className="text-xs text-muted-foreground">{title}</p>
          <CurrencyDisplay
            cents={cents}
            className="text-lg font-bold text-foreground"
          />
          {sub && <p className="text-[10px] text-muted-foreground">{sub}</p>}
        </div>
      </CardContent>
    </Card>
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
    <div className="text-center">
      <p className="text-sm text-muted-foreground">{label}</p>
      <p
        className={`mt-1 text-3xl font-bold ${highlight ? "text-primary" : "text-foreground"}`}
      >
        {value}
      </p>
    </div>
  );
}
