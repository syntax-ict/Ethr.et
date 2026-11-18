"use client";

import { Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { useT } from "@/lib/i18n/useT";
import type { JoinState } from "../join-split-days";

/**
 * Where joining split days has got to, with the way to stop it and, once it
 * has stopped or failed, the way to carry on. Shown only while there is
 * something to say; the finished totals are the caller's to word.
 */
export function JoinProgress({
  state,
  onStop,
  onCarryOn,
}: {
  state: JoinState;
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
          `${t("attendance.conflicts.join_stopped", "Stopped.")} `}
        {state.phase === "failed" &&
          `${
            state.error ??
            t(
              "attendance.conflicts.join_failed",
              "It stopped before it finished.",
            )
          } `}
        {t(
          "attendance.conflicts.join_progress",
          ":joined joined, :remaining still to look at",
          { joined: state.joined, remaining: state.remaining },
        )}
      </span>
      {state.phase === "running" ? (
        <Button size="sm" variant="outline" onClick={onStop}>
          {t("attendance.conflicts.join_stop", "Stop")}
        </Button>
      ) : (
        <Button size="sm" onClick={onCarryOn}>
          {t("attendance.conflicts.join_carry_on", "Carry on")}
        </Button>
      )}
    </div>
  );
}
