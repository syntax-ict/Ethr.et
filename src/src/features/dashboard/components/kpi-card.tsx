"use client";

import type { LucideIcon } from "lucide-react";
import { StatCard } from "@/components/dashboard/stat-card";

export type KpiTone = "info" | "success" | "warning" | "error" | "primary";

interface KpiCardProps {
  icon: LucideIcon;
  tone: KpiTone;
  title: string;
  value: string;
  sub: string;
  trend?: { value: number; label?: string };
  href?: string;
  className?: string;
  spark?: number[];
}

/**
 * The dashboard's original KPI tile, now drawn by the shared `StatCard` so
 * every screen that still imports it (team overview, the platform console,
 * billing) matches the rest. Its hover overlay never showed — an inline
 * `opacity: 0` overrode the hover class — and `href` was accepted and ignored;
 * `StatCard` makes the card a link when one is given.
 */
export function KpiCard({
  icon,
  tone,
  title,
  value,
  sub,
  trend,
  href,
  className,
  spark,
}: KpiCardProps) {
  return (
    <StatCard
      icon={icon}
      tone={tone}
      label={title}
      value={value}
      hint={sub}
      delta={trend ? { value: trend.value, label: trend.label } : undefined}
      href={href}
      spark={spark}
      className={className}
    />
  );
}
