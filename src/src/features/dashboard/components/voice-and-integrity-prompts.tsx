"use client";

import Link from "next/link";
import { ArrowRight, HeartHandshake } from "lucide-react";
import { useT } from "@/lib/i18n/useT";
import { usePulse } from "@/features/voice/api";

/**
 * Asks the weekly pulse question on the dashboard until it is answered, so
 * the people least likely to go looking for it are asked anyway. Gone once
 * answered.
 *
 * The attendance-review prompt that shared this file now lives in the Next
 * Steps list (`features/next-steps`), where it is counted with everything
 * else that needs a person.
 */
export function PulsePrompt() {
  const { t } = useT();
  const { data } = usePulse();

  if (!data || data.answered) return null;

  return (
    <Link
      href="/voice"
      className="group flex items-center gap-3 rounded-xl border border-l-4 border-l-primary bg-card p-4 shadow-xs transition-colors hover:bg-muted/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
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
