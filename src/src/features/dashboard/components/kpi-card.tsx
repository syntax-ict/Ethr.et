"use client";

import type { LucideIcon } from "lucide-react";
import { cn } from "@/lib/utils";
import { TrendingUp, TrendingDown, Minus } from "lucide-react";

export type KpiTone = "info" | "success" | "warning" | "error" | "primary";

const toneConfig: Record<KpiTone, { bg: string; text: string; ring: string }> =
  {
    info: {
      bg: "bg-status-info/8",
      text: "text-status-info",
      ring: "ring-status-info/20",
    },
    success: {
      bg: "bg-status-success/8",
      text: "text-status-success",
      ring: "ring-status-success/20",
    },
    warning: {
      bg: "bg-status-warning/8",
      text: "text-status-warning",
      ring: "ring-status-warning/20",
    },
    error: {
      bg: "bg-status-error/8",
      text: "text-status-error",
      ring: "ring-status-error/20",
    },
    primary: {
      bg: "bg-interactive-primary/8",
      text: "text-interactive-primary",
      ring: "ring-interactive-primary/20",
    },
  };

interface KpiCardProps {
  icon: LucideIcon;
  tone: KpiTone;
  title: string;
  value: string;
  sub: string;
  trend?: { value: number; label?: string };
  href?: string;
  className?: string;
}

export function KpiCard({
  icon: Icon,
  tone,
  title,
  value,
  sub,
  trend,
  className,
}: KpiCardProps) {
  const cfg = toneConfig[tone];

  return (
    <div
      className={cn(
        "group relative overflow-hidden rounded-xl border border-border/60 bg-card p-5 transition-all duration-300 hover:border-border hover:shadow-md",
        className,
      )}
    >
      {/* Subtle gradient overlay */}
      <div
        className={cn(
          "pointer-events-none absolute inset-0 opacity-0 transition-opacity duration-300 group-hover:opacity-100",
          cfg.bg,
        )}
        style={{ opacity: 0 }}
      />
      <div
        className="pointer-events-none absolute inset-0 opacity-0 transition-opacity duration-300 group-hover:opacity-[0.03]"
        style={{
          background:
            "linear-gradient(135deg, currentColor 0%, transparent 60%)",
        }}
      />

      <div className="relative flex items-start justify-between">
        <div className="min-w-0 flex-1">
          <p className="text-xs font-medium uppercase tracking-wider text-muted-foreground">
            {title}
          </p>
          <p className="mt-2 text-2xl font-bold leading-tight tabular-nums text-foreground transition-transform duration-300 group-hover:translate-x-0.5">
            {value}
          </p>
          <div className="mt-1.5 flex items-center gap-2">
            <p className="text-xs text-muted-foreground">{sub}</p>
            {trend && <TrendBadge value={trend.value} label={trend.label} />}
          </div>
        </div>
        <div
          className={cn(
            "ml-4 flex h-11 w-11 shrink-0 items-center justify-center rounded-xl ring-1 transition-all duration-300 group-hover:scale-110 group-hover:shadow-sm",
            cfg.bg,
            cfg.ring,
          )}
        >
          <Icon className={cn("h-5 w-5", cfg.text)} />
        </div>
      </div>
    </div>
  );
}

function TrendBadge({ value, label }: { value: number; label?: string }) {
  const isPositive = value > 0;
  const isNeutral = value === 0;
  const Icon = isNeutral ? Minus : isPositive ? TrendingUp : TrendingDown;

  return (
    <span
      className={cn(
        "inline-flex items-center gap-0.5 rounded-full px-1.5 py-0.5 text-[10px] font-semibold tabular-nums",
        isNeutral && "bg-muted text-muted-foreground",
        isPositive && "bg-success-soft text-success-on-soft",
        !isPositive &&
          !isNeutral &&
          "bg-destructive-soft text-destructive-on-soft",
      )}
    >
      <Icon className="h-2.5 w-2.5" />
      {Math.abs(value)}%{label ? ` ${label}` : ""}
    </span>
  );
}
