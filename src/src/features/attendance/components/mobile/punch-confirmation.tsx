"use client";

import { useEffect, useRef, type ReactNode } from "react";
import Link from "next/link";
import { History, Info } from "lucide-react";
import { Button } from "@/components/ui/button";
import type { OutcomeTone } from "@/features/attendance/punch-outcome";
import { useT } from "@/lib/i18n/useT";
import { cn } from "@/lib/utils";

const TONE: Record<OutcomeTone, string> = {
  success: "bg-success-soft text-success-on-soft",
  warning: "bg-warning-soft text-warning-on-soft",
  info: "bg-info-soft text-info-on-soft",
};

/**
 * What the punch came to, in the place the punch button was: a disc of the
 * same footprint, the title, the hint, at most one line more, and the two ways
 * on. It takes focus when it appears, so a screen reader hears it.
 */
export function PunchConfirmation({
  tone,
  title,
  hint,
}: {
  tone: OutcomeTone;
  title: ReactNode;
  hint: string | null;
}) {
  const { t } = useT();
  const ref = useRef<HTMLDivElement | null>(null);

  useEffect(() => {
    // Focus without the browser's own jump, then bring the block into view
    // ourselves: the bottom navigation is fixed over the scrolling area, so
    // "nearest" with a scroll margin (scroll-mb-24) keeps the buttons clear of
    // it. jsdom has no scrollIntoView, hence the optional call.
    ref.current?.focus({ preventScroll: true });
    ref.current?.scrollIntoView?.({ block: "nearest", behavior: "smooth" });
  }, []);

  return (
    <div
      ref={ref}
      tabIndex={-1}
      role="status"
      aria-live="polite"
      className="flex flex-col items-center gap-3 scroll-mb-24 py-2 text-center focus:outline-none"
    >
      <span
        className={cn(
          "flex h-28 w-28 items-center justify-center rounded-full",
          TONE[tone],
        )}
      >
        {tone === "info" ? (
          <Info className="h-12 w-12" aria-hidden="true" />
        ) : (
          <svg
            aria-hidden="true"
            viewBox="0 0 24 24"
            className="h-12 w-12"
            fill="none"
            stroke="currentColor"
            strokeWidth="2.25"
            strokeLinecap="round"
            strokeLinejoin="round"
          >
            <path
              d="M5 12.5l4.5 4.5L19 7"
              pathLength={1}
              className="ethr-punch-check"
            />
          </svg>
        )}
      </span>
      <div className="space-y-1">
        <p className="font-display text-xl font-semibold text-foreground">
          {title}
        </p>
        {hint && <p className="text-sm text-muted-foreground">{hint}</p>}
      </div>
      <div className="flex justify-center gap-2 pt-1">
        <Button asChild variant="outline" className="min-h-11">
          <Link href="/attendance">
            <History className="mr-2 h-4 w-4" aria-hidden="true" />
            {t("attendance.punch.history", "My history")}
          </Link>
        </Button>
        <Button asChild className="min-h-11">
          <Link href="/dashboard">{t("attendance.punch.done", "Done")}</Link>
        </Button>
      </div>
    </div>
  );
}
