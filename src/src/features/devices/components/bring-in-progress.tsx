"use client";

import { Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { useT } from "@/lib/i18n/useT";
import type { BringInState } from "../bring-in";

/**
 * Where bringing held punches in has got to, with the way to stop it and, once
 * it has stopped or failed, the way to carry on. Shown only while there is
 * something to say; the finished totals are the caller's to word.
 */
export function BringInProgress({
  state,
  onStop,
  onCarryOn,
}: {
  state: BringInState;
  onStop: () => void;
  onCarryOn: () => void;
}) {
  const { t } = useT();

  if (
    state.phase !== "running" &&
    state.phase !== "stopped" &&
    state.phase !== "failed"
  ) {
    return null;
  }

  return (
    <div
      className="flex flex-wrap items-center gap-2 text-sm"
      role="status"
      aria-live="polite"
    >
      {state.phase === "running" && (
        <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />
      )}
      <span className="text-foreground">
        {state.phase === "stopped" &&
          `${t("devices_page.unmatched.stopped", "Stopped.")} `}
        {state.phase === "failed" &&
          `${
            state.error ??
            t(
              "devices_page.unmatched.carry_failed",
              "It stopped before it finished.",
            )
          } `}
        {t(
          "devices_page.unmatched.progress",
          ":done brought in, :left still to go",
          { done: state.replayed, left: state.remaining },
        )}
      </span>
      {state.phase === "running" ? (
        <Button size="sm" variant="outline" onClick={onStop}>
          {t("devices_page.unmatched.stop", "Stop")}
        </Button>
      ) : (
        <Button size="sm" onClick={onCarryOn}>
          {t("devices_page.unmatched.carry_on", "Carry on")}
        </Button>
      )}
    </div>
  );
}
