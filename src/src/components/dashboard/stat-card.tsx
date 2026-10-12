"use client";

import Link from "next/link";
import type { ComponentType, ReactNode } from "react";
import { ArrowDownRight, ArrowUpRight, Minus } from "lucide-react";
import { Skeleton } from "@/components/ui/skeleton";
import { cn } from "@/lib/utils";
import { Sparkline } from "./sparkline";

export type StatTone =
  "primary" | "info" | "success" | "warning" | "error" | "brand" | "neutral";

/** Icon chip colours: the soft-container tokens, so both themes and high contrast hold. */
const TONE: Record<StatTone, { chip: string; spark: string }> = {
  primary: {
    chip: "bg-primary-soft text-primary-on-soft",
    spark: "var(--chart-1)",
  },
  info: { chip: "bg-info-soft text-info-on-soft", spark: "var(--status-info)" },
  success: {
    chip: "bg-success-soft text-success-on-soft",
    spark: "var(--status-success)",
  },
  warning: {
    chip: "bg-warning-soft text-warning-on-soft",
    spark: "var(--status-warning)",
  },
  error: {
    chip: "bg-destructive-soft text-destructive-on-soft",
    spark: "var(--status-error)",
  },
  brand: { chip: "bg-brand-soft text-brand-on-soft", spark: "var(--chart-3)" },
  neutral: {
    chip: "bg-neutral-soft text-neutral-on-soft",
    spark: "var(--chart-6)",
  },
};

export interface StatDelta {
  /** Signed change. Rendered as its absolute value with an arrow. */
  value: number;
  /** "%" by default; "" for a plain count. */
  unit?: string;
  /** Short context after the number, e.g. "vs last month". */
  label?: string;
  /**
   * Whether a rise is good news. Turnover and late arrivals going up is
   * bad, so the colour follows meaning rather than direction.
   */
  positiveIsGood?: boolean;
}

export interface StatCardProps {
  label: string;
  value: ReactNode;
  icon?: ComponentType<{ className?: string }>;
  tone?: StatTone;
  /** One line under the value: what the number counts, or its period. */
  hint?: ReactNode;
  delta?: StatDelta;
  /** A small trend line drawn under the figure; needs at least two points. */
  spark?: number[];
  /** Makes the whole card a link to the screen that explains the number. */
  href?: string;
  loading?: boolean;
  className?: string;
}

/**
 * The one KPI tile for every dashboard.
 *
 * There were seven — `KpiCard`, `KpiBox`, `KpiBoxCurrency`, `StatusCard` and
 * three local `Kpi`s — each with its own padding, value size and icon
 * position, so the same kind of number looked different on every screen.
 * Label on top, the figure large, context underneath, and the icon as a quiet
 * tinted chip: the figure is what the eye should land on.
 */
export function StatCard({
  label,
  value,
  icon: Icon,
  tone = "primary",
  hint,
  delta,
  spark,
  href,
  loading,
  className,
}: StatCardProps) {
  const cfg = TONE[tone];

  if (loading) {
    return (
      <div
        className={cn(
          "rounded-xl border border-border bg-card p-5 shadow-xs",
          className,
        )}
      >
        <Skeleton className="h-4 w-24" />
        <Skeleton className="mt-4 h-8 w-28" />
        <Skeleton className="mt-3 h-3 w-36" />
      </div>
    );
  }

  const body = (
    <>
      <div className="flex items-start justify-between gap-3">
        <p className="min-w-0 text-sm font-medium text-muted-foreground">
          {label}
        </p>
        {Icon && (
          <span
            className={cn(
              "flex h-9 w-9 shrink-0 items-center justify-center rounded-lg",
              cfg.chip,
            )}
            aria-hidden="true"
          >
            <Icon className="h-[18px] w-[18px]" />
          </span>
        )}
      </div>

      <div className="mt-2 flex items-end justify-between gap-3">
        <div className="min-w-0 text-2xl font-semibold leading-tight tracking-tight text-foreground tabular-nums sm:text-[1.75rem]">
          {value}
        </div>
        {spark && spark.length > 1 && (
          <Sparkline
            values={spark}
            color={cfg.spark}
            className="mb-1 h-8 w-24 shrink-0"
          />
        )}
      </div>

      {(delta || hint) && (
        <div className="mt-2 flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
          {delta && <DeltaBadge {...delta} />}
          {hint && <span className="min-w-0 flex-1 truncate">{hint}</span>}
        </div>
      )}
    </>
  );

  const shell = cn(
    "group relative block rounded-xl border border-border bg-card p-5 text-card-foreground shadow-xs transition-[box-shadow,border-color] duration-200",
    className,
  );

  if (href) {
    return (
      <Link
        href={href}
        className={cn(
          shell,
          "hover:border-border-strong hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background",
        )}
      >
        {body}
      </Link>
    );
  }

  return <div className={shell}>{body}</div>;
}

export function DeltaBadge({
  value,
  unit = "%",
  label,
  positiveIsGood = true,
}: StatDelta) {
  const flat = value === 0;
  const up = value > 0;
  const good = flat ? null : up === positiveIsGood;
  const Arrow = flat ? Minus : up ? ArrowUpRight : ArrowDownRight;

  return (
    <span className="inline-flex items-center gap-1.5">
      <span
        className={cn(
          "inline-flex items-center gap-0.5 rounded-md px-1.5 py-0.5 text-xs font-semibold tabular-nums",
          good === null && "bg-neutral-soft text-neutral-on-soft",
          good === true && "bg-success-soft text-success-on-soft",
          good === false && "bg-destructive-soft text-destructive-on-soft",
        )}
      >
        <Arrow className="h-3 w-3" aria-hidden="true" />
        {Math.abs(value)}
        {unit}
      </span>
      {label && <span>{label}</span>}
    </span>
  );
}
