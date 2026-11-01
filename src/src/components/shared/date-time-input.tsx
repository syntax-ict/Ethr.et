"use client";

import { useState } from "react";
import { DualCalendarDateInput } from "@/components/shared/dual-calendar-date-input";
import { ClockTimeInput } from "@/components/shared/clock-time-input";

/**
 * A drop-in replacement for `<input type="datetime-local">`: a date in the
 * reader's calendar and a time on the reader's clock, combined into the same
 * naive `YYYY-MM-DDTHH:MM` string the native input produces. Until both halves
 * are set the value is `""`, as a half-filled native input reports.
 */
export function DateTimeInput({
  id,
  value,
  onChange,
  minDate,
  required,
  timeLabel,
}: {
  id: string;
  value: string;
  onChange: (value: string) => void;
  minDate?: string;
  required?: boolean;
  timeLabel: string;
}) {
  const [draft, setDraft] = useState<{ date: string; time: string } | null>(
    null,
  );
  const [valueDate = "", valueTime = ""] = value ? value.split("T") : [];
  const date = draft?.date ?? valueDate;
  const time = draft?.time ?? valueTime;

  function change(part: "date" | "time", next: string) {
    const parts = { date, time, [part]: next };
    if (parts.date && parts.time) {
      setDraft(null);
      onChange(`${parts.date}T${parts.time}`);
    } else {
      setDraft(parts);
      onChange("");
    }
  }

  return (
    <div className="mt-1 flex flex-wrap items-start gap-2">
      <DualCalendarDateInput
        id={id}
        value={date}
        min={minDate}
        required={required}
        onChange={(next) => change("date", next)}
      />
      <ClockTimeInput
        value={time}
        required={required}
        aria-label={timeLabel}
        onChange={(next) => change("time", next)}
      />
    </div>
  );
}
