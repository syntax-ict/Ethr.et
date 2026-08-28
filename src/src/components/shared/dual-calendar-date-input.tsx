"use client";

import { useId } from "react";
import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { useCalendar } from "@/lib/calendar/calendar-context";
import { useT } from "@/lib/i18n/useT";
import {
  toEthiopian,
  toGregorian,
  formatEthiopian,
  daysInEthiopianMonth,
  ETHIOPIAN_MONTHS,
  ETHIOPIAN_MONTHS_AM,
  type EthiopianDate,
} from "@/lib/calendar/ethiopian";
import { cn } from "@/lib/utils";

/**
 * A drop-in replacement for `<Input type="date">` whose value contract is the
 * same ISO `YYYY-MM-DD` string, so migrating a surface is a near-swap.
 *
 * When the tenant's calendar preference is Gregorian it renders the native date
 * input (best keyboard/native-picker support) and shows the Ethiopian
 * equivalent beneath it. When the preference is Ethiopian it renders an
 * Ethiopian-native entry (year / month / day), converting to a Gregorian ISO
 * string on change, and shows the Gregorian equivalent beneath. Either way the
 * caller only ever sees ISO Gregorian — the storage/API format never changes.
 *
 * This is the dual-calendar *input* the design system called for; the existing
 * `DualCalendarDate` is display-only.
 */

interface DualCalendarDateInputProps {
  value: string; // ISO YYYY-MM-DD, or ""
  onChange: (iso: string) => void;
  id?: string;
  required?: boolean;
  disabled?: boolean;
  min?: string;
  max?: string;
  className?: string;
  "aria-label"?: string;
  /** Set by `FormField` so a validation failure reaches assistive tech and
   *  turns the control's border destructive, exactly like a plain `Input`. */
  "aria-invalid"?: boolean;
  "aria-required"?: boolean;
  "aria-describedby"?: string;
}

/** Parse an ISO `YYYY-MM-DD` into local date parts (no timezone shift). */
function parseIso(
  iso: string,
): { year: number; month: number; day: number } | null {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso);
  if (!m) return null;
  return { year: Number(m[1]), month: Number(m[2]), day: Number(m[3]) };
}

/** Format local date parts back to ISO, zero-padded. */
function toIso(year: number, month: number, day: number): string {
  const p = (n: number, w = 2) => String(n).padStart(w, "0");
  return `${p(year, 4)}-${p(month)}-${p(day)}`;
}

export function DualCalendarDateInput({
  value,
  onChange,
  id,
  required,
  disabled,
  min,
  max,
  className,
  "aria-label": ariaLabel,
  "aria-invalid": ariaInvalid,
  "aria-required": ariaRequired,
  "aria-describedby": ariaDescribedBy,
}: DualCalendarDateInputProps) {
  const { calendar } = useCalendar();
  const { t, locale } = useT();
  const generatedId = useId();
  const fieldId = id ?? generatedId;

  const parsed = parseIso(value);

  // Secondary (opposite-calendar) readout of whatever is currently entered.
  const secondary = parsed
    ? calendar === "ethiopian"
      ? new Intl.DateTimeFormat(locale === "am" ? "am-ET" : "en-US", {
          year: "numeric",
          month: "long",
          day: "numeric",
        }).format(new Date(parsed.year, parsed.month - 1, parsed.day))
      : formatEthiopian(
          toEthiopian(parsed.year, parsed.month, parsed.day),
          locale,
        )
    : null;

  if (calendar !== "ethiopian") {
    return (
      <div className={className}>
        <Input
          id={fieldId}
          type="date"
          required={required}
          disabled={disabled}
          min={min}
          max={max}
          aria-label={ariaLabel}
          aria-invalid={ariaInvalid}
          aria-required={ariaRequired}
          aria-describedby={ariaDescribedBy}
          value={value}
          onChange={(e) => onChange(e.target.value)}
        />
        {secondary && (
          <p className="mt-1 text-xs text-muted-foreground">{secondary}</p>
        )}
      </div>
    );
  }

  // Ethiopian entry mode.
  const eth: EthiopianDate | null = parsed
    ? toEthiopian(parsed.year, parsed.month, parsed.day)
    : null;
  const months = locale === "am" ? ETHIOPIAN_MONTHS_AM : ETHIOPIAN_MONTHS;

  function emit(next: EthiopianDate) {
    // Clamp the day to the (possibly shorter, e.g. Pagume) month length.
    const maxDay = daysInEthiopianMonth(next.year, next.month);
    const day = Math.min(Math.max(next.day, 1), maxDay);
    const greg = toGregorian(next.year, next.month, day);
    onChange(toIso(greg.getFullYear(), greg.getMonth() + 1, greg.getDate()));
  }

  const currentYear = eth?.year ?? "";
  const currentMonth = eth?.month ?? "";
  const currentDay = eth?.day ?? "";
  // Day options depend on the selected month (Pagume is 5 or 6 days).
  const dayCount = eth != null ? daysInEthiopianMonth(eth.year, eth.month) : 30;

  return (
    <div className={className}>
      <div className="flex gap-2">
        <Input
          id={fieldId}
          type="number"
          inputMode="numeric"
          required={required}
          disabled={disabled}
          aria-label={t("calendar.ethiopian_year", "Ethiopian year")}
          // The label from FormField points at this control (it carries
          // `fieldId`), so the invalid/described wiring belongs here rather
          // than on the month or day selects beside it.
          aria-invalid={ariaInvalid}
          aria-required={ariaRequired}
          aria-describedby={ariaDescribedBy}
          placeholder={t("calendar.year", "Year")}
          className="w-24"
          value={currentYear}
          onChange={(e) => {
            const year = Number(e.target.value);
            if (!Number.isFinite(year) || year < 1) return;
            emit({ year, month: eth?.month ?? 1, day: eth?.day ?? 1 });
          }}
        />
        <Select
          disabled={disabled}
          value={currentMonth === "" ? undefined : String(currentMonth)}
          onValueChange={(v) =>
            emit({
              year: eth?.year ?? todayEthYear(),
              month: Number(v),
              day: eth?.day ?? 1,
            })
          }
        >
          <SelectTrigger
            className="flex-1"
            aria-label={t("calendar.ethiopian_month", "Ethiopian month")}
          >
            <SelectValue placeholder={t("calendar.month", "Month")} />
          </SelectTrigger>
          <SelectContent>
            {months.map((name, i) => (
              <SelectItem key={name} value={String(i + 1)}>
                {name}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
        <Select
          disabled={disabled}
          value={currentDay === "" ? undefined : String(currentDay)}
          onValueChange={(v) =>
            emit({
              year: eth?.year ?? todayEthYear(),
              month: eth?.month ?? 1,
              day: Number(v),
            })
          }
        >
          <SelectTrigger
            className="w-20"
            aria-label={t("calendar.ethiopian_day", "Ethiopian day")}
          >
            <SelectValue placeholder={t("calendar.day", "Day")} />
          </SelectTrigger>
          <SelectContent>
            {Array.from({ length: dayCount }, (_, i) => i + 1).map((d) => (
              <SelectItem key={d} value={String(d)}>
                {d}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>
      <p className={cn("mt-1 text-xs text-muted-foreground")}>
        {secondary ??
          t(
            "calendar.ethiopian_entry_hint",
            "Enter the date in the Ethiopian calendar",
          )}
      </p>
    </div>
  );
}

function todayEthYear(): number {
  const now = new Date();
  return toEthiopian(now.getFullYear(), now.getMonth() + 1, now.getDate()).year;
}
