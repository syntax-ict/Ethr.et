"use client";

import type { ReactNode } from "react";
import { cn } from "@/lib/utils";

/**
 * Shared recharts styling, so every chart reads as one product.
 *
 * The dashboards had two palettes (`--chart-*` on the home page, a mix of
 * status and brand tokens on Analytics), and most Analytics charts used
 * recharts' default tooltip: a white box with black text, which in dark mode
 * was a bright rectangle with the wrong colours. Everything here is a CSS
 * variable, so light, dark and high contrast all follow the theme.
 */

/** Categorical series colours, in assignment order. */
export const CHART_PALETTE = [
  "var(--chart-1)",
  "var(--chart-2)",
  "var(--chart-3)",
  "var(--chart-4)",
  "var(--chart-5)",
  "var(--chart-6)",
] as const;

export function seriesColor(index: number): string {
  return CHART_PALETTE[index % CHART_PALETTE.length];
}

/** Series that mean something: these follow the status tokens, not the palette. */
export const STATUS_SERIES = {
  present: "var(--status-success)",
  late: "var(--status-warning)",
  absent: "var(--status-error)",
  info: "var(--status-info)",
} as const;

const tick = { fontSize: 11, fill: "var(--muted-foreground)" };

/** Spread onto `<XAxis>` / `<YAxis>`. */
export const axisProps = {
  tick,
  axisLine: false,
  tickLine: false,
  tickMargin: 8,
} as const;

/** Spread onto `<CartesianGrid>`: horizontal hairlines only. */
export const gridProps = {
  stroke: "var(--border)",
  strokeDasharray: "3 4",
  vertical: false,
} as const;

/** Spread onto `<Tooltip>`. */
export const tooltipProps = {
  content: <ChartTooltipContent />,
  cursor: { fill: "var(--muted)", fillOpacity: 0.6 },
  wrapperStyle: { outline: "none" },
} as const;

/** For line and area charts, where a filled cursor block would hide the line. */
export const lineTooltipProps = {
  ...tooltipProps,
  cursor: { stroke: "var(--border-strong)", strokeDasharray: "3 3" },
} as const;

interface TooltipEntry {
  name?: ReactNode;
  value?: number | string | Array<number | string>;
  color?: string;
  fill?: string;
  dataKey?: string | number;
  payload?: Record<string, unknown>;
}

/**
 * A themed tooltip body. recharts clones this element and passes `active`,
 * `payload` and `label`; `formatter` and `labelFormatter` may be set by the
 * chart that uses it.
 */
export function ChartTooltipContent({
  active,
  payload,
  label,
  formatter,
  labelFormatter,
}: {
  active?: boolean;
  payload?: TooltipEntry[];
  label?: ReactNode;
  formatter?: (value: number | string, name: ReactNode) => ReactNode;
  labelFormatter?: (label: ReactNode) => ReactNode;
}) {
  if (!active || !payload || payload.length === 0) return null;

  const heading = labelFormatter ? labelFormatter(label) : label;

  return (
    <div className="min-w-36 rounded-lg border border-border bg-popover px-3 py-2 text-xs text-popover-foreground shadow-lg">
      {heading !== undefined && heading !== null && heading !== "" && (
        <p className="mb-1.5 font-medium text-foreground">{heading}</p>
      )}
      <ul className="space-y-1">
        {payload.map((entry, i) => {
          const raw = Array.isArray(entry.value)
            ? entry.value.join(" – ")
            : (entry.value ?? "");
          const shown = formatter ? formatter(raw, entry.name) : raw;
          const swatch =
            entry.color ??
            entry.fill ??
            (entry.payload?.fill as string | undefined) ??
            "var(--chart-1)";
          return (
            <li
              key={`${String(entry.dataKey ?? entry.name)}-${i}`}
              className="flex items-center gap-2"
            >
              <span
                className="h-2 w-2 shrink-0 rounded-full"
                style={{ background: swatch }}
                aria-hidden="true"
              />
              <span className="min-w-0 flex-1 truncate text-muted-foreground">
                {entry.name}
              </span>
              <span className="font-semibold tabular-nums text-foreground">
                {shown}
              </span>
            </li>
          );
        })}
      </ul>
    </div>
  );
}

/**
 * A legend for charts that draw their own (recharts' is unstyled). Inline by
 * default; `stacked` lists one series per row with its value right-aligned,
 * for the side of a donut.
 */
export function ChartLegend({
  items,
  stacked = false,
  className,
}: {
  items: { label: ReactNode; color: string; value?: ReactNode }[];
  stacked?: boolean;
  className?: string;
}) {
  return (
    <ul
      className={cn(
        stacked
          ? "w-full min-w-0 space-y-2.5 text-sm"
          : "flex flex-wrap items-center gap-x-4 gap-y-1 text-xs",
        "text-muted-foreground",
        className,
      )}
    >
      {items.map((item, i) => (
        <li key={i} className="flex min-w-0 items-center gap-2">
          <span
            className="h-2.5 w-2.5 shrink-0 rounded-full"
            style={{ background: item.color }}
            aria-hidden="true"
          />
          <span className={cn("min-w-0 truncate", stacked && "flex-1")}>
            {item.label}
          </span>
          {item.value !== undefined && (
            <span className="shrink-0 font-semibold tabular-nums text-foreground">
              {item.value}
            </span>
          )}
        </li>
      ))}
    </ul>
  );
}
