"use client";

import Link from "next/link";
import { ArrowRight, HeartHandshake, ShieldAlert } from "lucide-react";
import { useT } from "@/lib/i18n/useT";
import { usePulse } from "@/features/voice/api";
import { useIntegritySummary } from "@/features/attendance/api";

/**
 * Asks the weekly pulse question on the dashboard until it is answered, so
 * the people least likely to go looking for it are asked anyway. Gone once
 * answered.
 */
export function PulsePrompt() {
  const { t } = useT();
  const { data } = usePulse();

  if (!data || data.answered) return null;

  return (
    <Link
      href="/voice"
      className="group flex items-center gap-3 rounded-xl border border-primary/20 bg-primary/5 p-4 transition-colors hover:bg-primary/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
    >
      <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary text-primary-foreground">
        <HeartHandshake className="h-5 w-5" aria-hidden="true" />
      </span>
      <span className="min-w-0 flex-1">
        <span className="block text-sm font-semibold text-foreground">
          {t("voice.pulse_question", "How was work for you this week?")}
        </span>
        <span className="block text-xs text-muted-foreground">
          {t("voice.prompt_hint", "Ten seconds, and anonymous.")}
        </span>
      </span>
      <ArrowRight
        className="h-4 w-4 text-muted-foreground transition-transform group-hover:translate-x-0.5"
        aria-hidden="true"
      />
    </Link>
  );
}

/** For whoever reviews attendance: punches waiting on a decision. */
export function IntegrityPrompt() {
  const { t } = useT();
  const { data } = useIntegritySummary();

  if (!data || data.open === 0) return null;

  return (
    <Link
      href="/attendance/integrity"
      className="group flex items-center gap-3 rounded-xl border border-warning/30 bg-warning-soft/40 p-4 transition-colors hover:bg-warning-soft/70 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
    >
      <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-warning-soft text-warning-on-soft">
        <ShieldAlert className="h-5 w-5" aria-hidden="true" />
      </span>
      <span className="min-w-0 flex-1">
        <span className="block text-sm font-semibold text-foreground">
          {data.open === 1
            ? t("integrity.prompt_one", "1 punch to review")
            : t("integrity.prompt", ":count punches to review", {
                count: data.open,
              })}
        </span>
        <span className="block text-xs text-muted-foreground">
          {t(
            "integrity.prompt_hint",
            "Accept or reject before payroll is run.",
          )}
        </span>
      </span>
      <ArrowRight
        className="h-4 w-4 text-muted-foreground transition-transform group-hover:translate-x-0.5"
        aria-hidden="true"
      />
    </Link>
  );
}
