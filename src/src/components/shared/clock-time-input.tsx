"use client";

import { useState } from "react";
import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import {
  ETHIOPIAN_PERIODS,
  ethiopianPeriodLabel,
  fromEthiopianClock,
  toEthiopianClock,
  type EthiopianPeriod,
} from "@/lib/calendar/ethiopian-time";
import { useT } from "@/lib/i18n/useT";
import { cn } from "@/lib/utils";

/**
 * A drop-in replacement for `<Input type="time">`: the value is always a
 * 24-hour `HH:MM`, whatever the reader sees.
 *
 * On the 24-hour clock it is the native time input, unchanged. On the
 * Ethiopian clock it is an hour (1-12), a minute and a period (ጠዋት, ቀን,
 * ምሽት, ሌሊት), with the 24-hour time it stands for written underneath, so
 * nobody has to work out that 1:30 ጠዋት is 07:30 in their head. The API never
 * sees Ethiopian time.
 */
interface ClockTimeInputProps {
  value: string;
  onChange: (value: string) => void;
  id?: string;
  required?: boolean;
  disabled?: boolean;
  className?: string;
  "aria-label"?: string;
}

const HOURS = [12, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11];

/** The 12 readings a period can show: 12, 1 … 5 at dawn and dusk; 6 … 11 at noon and midnight. */
function hoursIn(period: EthiopianPeriod): number[] {
  return period === "morning" || period === "evening"
    ? [12, 1, 2, 3, 4, 5]
    : [6, 7, 8, 9, 10, 11];
}

function parse(value: string): { hour: number; minute: number } | null {
  const match = /^(\d{1,2}):(\d{2})/.exec(value);
  if (!match) return null;
  return { hour: Number(match[1]), minute: Number(match[2]) };
}

function hhmm(hour: number, minute: number): string {
  return `${String(hour).padStart(2, "0")}:${String(minute).padStart(2, "0")}`;
}

export function ClockTimeInput(props: ClockTimeInputProps) {
  const { clock } = useDateFormatters();

  if (clock === "24h") {
    return (
      <Input
        id={props.id}
        type="time"
        value={props.value}
        onChange={(e) => props.onChange(e.target.value)}
        required={props.required}
        disabled={props.disabled}
        className={props.className}
        aria-label={props["aria-label"]}
      />
    );
  }

  return <EthiopianTimeInput {...props} />;
}

function EthiopianTimeInput({
  value,
  onChange,
  id,
  required,
  disabled,
  className,
  "aria-label": ariaLabel,
}: ClockTimeInputProps) {
  const { t, locale } = useT();
  const parsed = parse(value);
  const current = parsed ? toEthiopianClock(parsed.hour, parsed.minute) : null;

  // A period chosen before an hour has nowhere to live in `value` yet.
  const [draftPeriod, setDraftPeriod] = useState<EthiopianPeriod>("morning");
  const period = current?.period ?? draftPeriod;
  const [minuteText, setMinuteText] = useState<string | null>(null);

  function emit(hour: number, minute: number, nextPeriod: EthiopianPeriod) {
    const allowed = hoursIn(nextPeriod);
    // Switching ጠዋት to ቀን keeps the minute and moves the hour into range.
    const reading = allowed.includes(hour) ? hour : allowed[0];
    const time = fromEthiopianClock({
      hour: reading,
      minute,
      period: nextPeriod,
    });
    onChange(hhmm(time.hour, time.minute));
  }

  const label = ariaLabel ?? t("clock.time", "Time");

  return (
    <div className={cn("space-y-1", className)}>
      <div className="flex items-center gap-2">
        <Select
          value={current ? String(current.hour) : ""}
          onValueChange={(v) => emit(Number(v), current?.minute ?? 0, period)}
          disabled={disabled}
        >
          <SelectTrigger
            id={id}
            className="w-20"
            aria-label={`${label}: ${t("clock.hour", "hour")}`}
            aria-required={required}
          >
            <SelectValue placeholder="--" />
          </SelectTrigger>
          <SelectContent>
            {HOURS.filter((h) => hoursIn(period).includes(h)).map((h) => (
              <SelectItem key={h} value={String(h)}>
                {h}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
        <span aria-hidden="true">:</span>
        <Input
          inputMode="numeric"
          className="w-16 tabular-nums"
          aria-label={`${label}: ${t("clock.minute", "minute")}`}
          value={
            minuteText ??
            (current ? String(current.minute).padStart(2, "0") : "")
          }
          placeholder="00"
          maxLength={2}
          disabled={disabled}
          onChange={(e) => {
            const text = e.target.value.replace(/\D/g, "").slice(0, 2);
            setMinuteText(text);
            const minute = Number(text);
            if (text !== "" && minute <= 59 && current) {
              emit(current.hour, minute, period);
            }
          }}
          onFocus={(e) => e.target.select()}
          onBlur={() => setMinuteText(null)}
        />
        <Select
          value={period}
          onValueChange={(v) => {
            const next = v as EthiopianPeriod;
            setDraftPeriod(next);
            if (current) emit(current.hour, current.minute, next);
          }}
          disabled={disabled}
        >
          <SelectTrigger
            className="w-28"
            aria-label={`${label}: ${t("clock.period", "part of the day")}`}
          >
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {ETHIOPIAN_PERIODS.map((p) => (
              <SelectItem key={p} value={p}>
                {ethiopianPeriodLabel(p, locale)}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>
      {parsed && (
        <p className="text-xs tabular-nums text-muted-foreground">
          {t("clock.equivalent", "24-hour: :time", {
            time: hhmm(parsed.hour, parsed.minute),
          })}
        </p>
      )}
    </div>
  );
}
