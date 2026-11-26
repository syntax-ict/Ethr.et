"use client";

import { Button } from "@/components/ui/button";
import { useT } from "@/lib/i18n/useT";
import type { OfflineAttendanceRecord } from "@/lib/offline-queue";

/**
 * Punches saved on this phone that the server refused for good. They are no
 * longer sent, so they would otherwise vanish from the screen while the day
 * still lacks them: each one is listed with the server's reason, the person is
 * told to ask HR to enter it, and removes it once they have noted it.
 */
export function RefusedPunches({
  records,
  whenOf,
  onRemove,
}: {
  records: OfflineAttendanceRecord[];
  /** The punch's date and time, in the shift's zone and the reader's calendar. */
  whenOf: (iso: string) => string;
  onRemove: (id: number) => void;
}) {
  const { t } = useT();
  if (records.length === 0) return null;

  return (
    <section
      aria-labelledby="refused-punches-title"
      className="animate-fade-in space-y-3 rounded-lg border border-warning-edge bg-warning-soft p-3 text-sm text-warning-on-soft"
    >
      <div className="space-y-1">
        <h2 id="refused-punches-title" className="font-medium">
          {t(
            "attendance.punch.refused_title",
            "Punches that were not recorded",
          )}
        </h2>
        <p>
          {t(
            "attendance.punch.refused_hint",
            "Ask HR to enter them for you. Remove each one here once you have noted it.",
          )}
        </p>
      </div>
      <ul className="space-y-2">
        {records.map((r) => (
          <li
            key={r.id ?? r.idempotency_key}
            className="flex items-start justify-between gap-3 rounded-md bg-card px-3 py-2 text-foreground"
          >
            <div id={`refused-${r.idempotency_key}`} className="min-w-0">
              <p className="font-medium">
                {r.type === "check_in"
                  ? t("common.check_in")
                  : t("common.check_out")}{" "}
                <span className="tabular-nums">{whenOf(r.captured_at)}</span>
              </p>
              <p className="text-muted-foreground">
                {r.refused_locally || !r.sync_error
                  ? t(
                      "attendance.punch.refused_local",
                      "This punch could not be sent, and the server will not accept it as it is.",
                    )
                  : r.sync_error}
              </p>
            </div>
            <Button
              size="sm"
              variant="outline"
              className="min-h-11 shrink-0"
              aria-describedby={`refused-${r.idempotency_key}`}
              onClick={() => r.id !== undefined && onRemove(r.id)}
            >
              {t("common.remove", "Remove")}
            </Button>
          </li>
        ))}
      </ul>
    </section>
  );
}
