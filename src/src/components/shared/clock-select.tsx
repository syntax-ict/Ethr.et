"use client";

import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  formatClock,
  type ClockPreference,
} from "@/lib/calendar/ethiopian-time";
import { useT } from "@/lib/i18n/useT";

export const CLOCK_CHOICES: readonly ClockPreference[] = [
  "auto",
  "ethiopian",
  "24h",
];

/**
 * Each clock named the way a reader can tell them apart: 07:30 written as that
 * clock writes it. Shared by this select and the header's language menu.
 */
export function useClockLabels(): Record<ClockPreference, string> {
  const { t, locale } = useT();
  return {
    auto: t("clock.auto", "Automatic"),
    ethiopian: t("clock.ethiopian", "Ethiopian time (:example)", {
      example: formatClock(7, 30, "ethiopian", locale),
    }),
    "24h": t("clock.24h", "24-hour (:example)", {
      example: formatClock(7, 30, "24h", locale),
    }),
  };
}

/**
 * Chooses how times of day are written: Ethiopian time, 24-hour, or `auto`.
 *
 * Each choice shows 07:30 the way it would be written, because "Ethiopian
 * time" alone does not tell a reader which hour 1:30 is. Used by Profile →
 * Preferences (the person's own) and General Settings (the organisation's).
 */
export function ClockSelect({
  id,
  value,
  onChange,
  disabled,
  className = "w-full sm:w-64",
}: {
  id: string;
  value: ClockPreference;
  onChange: (clock: ClockPreference) => void;
  disabled?: boolean;
  className?: string;
}) {
  const { t } = useT();
  const labels = useClockLabels();

  return (
    <div className="space-y-1">
      <Select
        value={value}
        onValueChange={(v) => onChange(v as ClockPreference)}
        disabled={disabled}
      >
        <SelectTrigger id={id} className={className}>
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          {CLOCK_CHOICES.map((choice) => (
            <SelectItem key={choice} value={choice}>
              {labels[choice]}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
      <p className="text-xs text-muted-foreground">
        {t(
          "clock.hint",
          "Automatic uses Ethiopian time when you read Amharic or Afaan Oromo on the Ethiopian calendar, and 24-hour otherwise.",
        )}
      </p>
    </div>
  );
}
