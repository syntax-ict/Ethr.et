"use client";

import Link from "next/link";
import type { ComponentType, ReactNode } from "react";
import { ArrowRight, ArrowUpRight } from "lucide-react";
import { Progress } from "@/components/ui/progress";
import { Skeleton } from "@/components/ui/skeleton";
import { cn } from "@/lib/utils";
import { Sparkline } from "./sparkline";
import { DeltaBadge, type StatDelta } from "./stat-card";

/**
 * One panel of figures that sum up the page, divided by hairlines.
 *
 * The figures at the top of the dashboard were four `StatCard`s: four boxes
 * of the same weight as every card under them, so nothing said where the
 * page began (owner review 2026-11-05). One band reads as one summary. The
 * hairlines are the panel's own colour showing through a 1 px gap, so they
 * stay right at two columns and at four.
 */
export function SummaryBand({
  children,
  className,
}: {
  children: ReactNode;
  className?: string;
}) {
  return (
    <div
      className={cn(
        "grid gap-px overflow-hidden rounded-xl border border-border bg-border shadow-xs sm:grid-cols-2 xl:grid-cols-4",
        className,
      )}
    >
      {children}
    </div>
  );
}

export interface SummaryCellProps {
  label: string;
  icon?: ComponentType<{ className?: string }>;
  /** The figure. A sentence instead of a number passes `quiet`. */
  value: ReactNode;
  /** Small words after the figure, such as "of 140 in". */
  suffix?: ReactNode;
  /** Words rather than a figure: drawn smaller, so they do not shout. */
  quiet?: boolean;
  hint?: ReactNode;
  delta?: StatDelta;
  /**
   * A trend line, with words naming its span ("14 days"). Gaps (null) are
   * left out; it needs two points to draw.
   */
  spark?: { values: (number | null)[]; caption: string; color: string };
  /** A bar under the figure, 0 to 100, with its accessible name. */
  meter?: { value: number; label: string };
  /** Something is waiting on the reader: a coloured edge and `action`. */
  attention?: boolean;
  /** Words on the button-like cue at the foot, shown with `attention`. */
  action?: string;
  href: string;
  loading?: boolean;
}

export function SummaryCell({
  label,
  icon: Icon,
  value,
  suffix,
  quiet,
  hint,
  delta,
  spark,
  meter,
  attention,
  action,
  href,
  loading,
}: SummaryCellProps) {
  if (loading) {
    return (
      <div className="bg-card p-5">
        <Skeleton className="h-4 w-24" />
        <Skeleton className="mt-4 h-8 w-28" />
        <Skeleton className="mt-4 h-3 w-36" />
      </div>
    );
  }

  const points = (spark?.values ?? []).filter((v): v is number => v !== null);
  const hasSpark = spark !== undefined && points.length > 1;
  const showAction = attention && action;

  return (
    <Link
      href={href}
      className="group relative flex min-w-0 flex-col bg-card p-5 text-card-foreground transition-colors hover:bg-muted/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring"
    >
      {attention && (
        <span
          className="absolute inset-y-0 left-0 w-1 bg-warning"
          aria-hidden="true"
        />
      )}

      <div className="flex items-center justify-between gap-2">
        <p className="flex min-w-0 items-center gap-1.5 text-sm font-medium text-muted-foreground">
          {Icon && (
            <Icon className="h-4 w-4 shrink-0 opacity-70" aria-hidden="true" />
          )}
          <span className="min-w-0 truncate">{label}</span>
        </p>
        <ArrowUpRight
          className="h-4 w-4 shrink-0 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100 group-focus-visible:opacity-100"
          aria-hidden="true"
        />
      </div>

      {/* The figure keeps its width and the line gives way: an Amharic
          dashboard at 1280 px once broke "33.3%" onto two lines (production
          QA 2026-10-28, F-04). The suffix may wrap under it. */}
      <div className="mt-3 flex items-end justify-between gap-3">
        <div className="flex min-w-0 flex-wrap items-baseline gap-x-1.5">
          <span
            className={cn(
              "whitespace-nowrap tabular-nums",
              quiet
                ? "text-lg font-medium leading-8 text-muted-foreground"
                : "text-3xl font-semibold leading-none tracking-tight text-foreground",
            )}
          >
            {value}
          </span>
          {suffix && (
            <span className="text-sm font-medium text-muted-foreground">
              {suffix}
            </span>
          )}
        </div>
        {hasSpark && (
          <div className="flex shrink-0 flex-col items-end">
            <Sparkline
              values={points}
              color={spark.color}
              className="h-8 w-20"
            />
            <span className="mt-0.5 text-caption text-muted-foreground">
              {spark.caption}
            </span>
          </div>
        )}
      </div>

      {meter && (
        <Progress
          value={Math.max(0, Math.min(100, meter.value))}
          label={meter.label}
          className="mt-3 h-1.5 bg-border"
        />
      )}

      <div className="mt-auto flex min-w-0 items-end justify-between gap-3 pt-3">
        {(delta || hint) && (
          <div className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
            {delta && <DeltaBadge {...delta} />}
            {hint && (
              <span
                className="line-clamp-2 min-w-0"
                title={typeof hint === "string" ? hint : undefined}
              >
                {hint}
              </span>
            )}
          </div>
        )}
        {showAction && (
          <span className="ml-auto inline-flex shrink-0 items-center gap-1 rounded-md border border-border bg-background px-2.5 py-1 text-xs font-semibold text-foreground shadow-xs transition-colors group-hover:border-border-strong">
            {action}
            <ArrowRight className="h-3.5 w-3.5" aria-hidden="true" />
          </span>
        )}
      </div>
    </Link>
  );
}
