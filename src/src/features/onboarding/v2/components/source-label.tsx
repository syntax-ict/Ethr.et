"use client";

import { cn } from "@/lib/utils";
import { useT } from "@/lib/i18n/useT";
import type { ConfigurationSource } from "../types";

/**
 * Where a proposed section came from, in words an administrator uses.
 *
 * This was a coloured "80% · Industry default" pill on every section, beside
 * an "Overall confidence 78%" figure. The percentage is a fixed number per
 * source (ConfigurationSource::confidence) — it measured nothing about the
 * organisation, and a score on a list of departments reads as a model's guess
 * rather than a proposal to review. The API still returns it; the screen says
 * only what it means (2026-10-14).
 */
export function SourceLabel({
  source,
  className,
}: {
  source: ConfigurationSource;
  className?: string;
}) {
  const { t } = useT();

  let text: string;
  switch (source) {
    case "explicit":
      text = t("setup2.source_explicit", "Tailored");
      break;
    case "industry_default":
      text = t("setup2.source_default", "Standard for this industry");
      break;
    case "heuristic":
      text = t("setup2.source_heuristic", "Based on your details");
      break;
    default:
      text = t("setup2.source_fallback", "General default");
  }

  return (
    <span className={cn("text-xs text-muted-foreground", className)}>
      {text}
    </span>
  );
}
