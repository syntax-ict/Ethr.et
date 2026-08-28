"use client";

import { Badge } from "@/components/ui/badge";
import { cn } from "@/lib/utils";
import { useT } from "@/lib/i18n/useT";
import type { ConfigurationSource } from "../types";

const SOURCE_LABEL: Record<ConfigurationSource, [string, string]> = {
  explicit: ["setup2.source_explicit", "Industry-specific"],
  industry_default: ["setup2.source_default", "Industry default"],
  heuristic: ["setup2.source_heuristic", "Suggested"],
  global_fallback: ["setup2.source_fallback", "Fallback"],
};

/** Color-codes confidence via semantic status tokens (never raw Tailwind colors). */
function toneFor(confidence: number): { color: string; bg: string } {
  if (confidence >= 0.85) {
    return {
      color: "var(--color-status-success, #059669)",
      bg: "color-mix(in srgb, var(--color-status-success, #059669) 12%, transparent)",
    };
  }
  if (confidence >= 0.6) {
    return {
      color: "var(--color-status-warning, #D97706)",
      bg: "color-mix(in srgb, var(--color-status-warning, #D97706) 14%, transparent)",
    };
  }
  return {
    color: "var(--color-text-secondary, #64748B)",
    bg: "color-mix(in srgb, var(--color-text-secondary, #64748B) 12%, transparent)",
  };
}

export function ConfidenceBadge({
  source,
  confidence,
  className,
}: {
  source: ConfigurationSource;
  confidence: number;
  className?: string;
}) {
  const { t } = useT();
  const [key, fallback] = SOURCE_LABEL[source];
  const tone = toneFor(confidence);

  return (
    <Badge
      variant="outline"
      className={cn(
        "gap-1 border-transparent text-[11px] font-medium",
        className,
      )}
      style={{ color: tone.color, background: tone.bg }}
      title={t(key, fallback)}
    >
      {Math.round(confidence * 100)}% · {t(key, fallback)}
    </Badge>
  );
}
