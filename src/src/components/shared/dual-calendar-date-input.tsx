"use client";

import { useId, useState, useSyncExternalStore } from "react";
import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { useOptionalCalendar } from "@/lib/calendar/calendar-context";
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
 *
 * `min` and `max` hold in both modes. The native input enforces them itself;
 * the Ethiopian entry offers only the months and days inside the range, moves
 * a carried day or month into it, and never emits a typed year that falls
 * wholly outside it. A value that arrives out of range is flagged, as the
 * native input flags it, rather than silently changed.
 *
 * Outside a `CalendarProvider` it is Gregorian, as `useDateFormatters()` is.
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

/** `(year, month, day)` as one comparable number. */
function ethKey(d: EthiopianDate): number {
  return d.year * 10000 + d.month * 100 + d.day;
}

function isoToEthiopian(iso: string | undefined): EthiopianDate | null {
  const parts = iso ? parseIso(iso) : null;
  return parts ? toEthiopian(parts.year, parts.month, parts.day) : null;
}

const noSubscription = () => () => {};

/**
 * The visible text of the `<label for>` pointing at `id`, minus a required
 * marker. Read through useSyncExternalStore because the label commits in the
 * same pass as this input: the store re-checks after mount, when it exists.
 */
function useLabelText(id: string): string {
  return useSyncExternalStore(
    noSubscription,
    () =>
      document
        .querySelector(`label[for="${CSS.escape(id)}"]`)
        ?.textContent?.replace(/\*/g, "")
        .trim() ?? "",
    () => "",
  );
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
  const calendar = useOptionalCalendar()?.calendar ?? "gregorian";
  const { t, locale } = useT();
  const generatedId = useId();
  const fieldId = id ?? generatedId;
  // The year input carries `fieldId`, so the form's <label> should name it —
  // but its own aria-label overrode that, and every Ethiopian date field
  // announced only "Ethiopian year / month / day", never "Hire date". Each
  // part now says which field it belongs to.
  const labelText = useLabelText(fieldId);
  const fieldName = ariaLabel ?? labelText;
  const partLabel = (part: string) =>
    fieldName ? `${fieldName}, ${part}` : part;
  // The year while it is being typed. Emitting a date on every keystroke made
  // "2" a whole date in year 2, which came back as 1902 and swallowed the
  // next digit (production QA 2026-10-11, P5). A year is committed once it
  // has four digits; null means "show the committed value".
  const [yearDraft, setYearDraft] = useState<string | null>(null);
  // A typed year whose every day falls outside min/max is not emitted, just
  // as a native input will not submit one; this says why.
  const [rejected, setRejected] = useState<"min" | "max" | null>(null);

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

  const minEth = isoToEthiopian(min);
  const maxEth = isoToEthiopian(max);
  const minYear = minEth?.year;
  const maxYear = maxEth?.year;
  const outOfRange = (d: EthiopianDate) =>
    (minEth != null && ethKey(d) < ethKey(minEth)) ||
    (maxEth != null && ethKey(d) > ethKey(maxEth));
  /** True when no day of `month` in `year` is inside min/max. */
  const monthOutside = (year: number, month: number) =>
    (minEth != null && year * 100 + month < minEth.year * 100 + minEth.month) ||
    (maxEth != null && year * 100 + month > maxEth.year * 100 + maxEth.month);

  function emit(next: EthiopianDate) {
    // Clamp the day to the (possibly shorter, e.g. Pagume) month length.
    const maxDay = daysInEthiopianMonth(next.year, next.month);
    let date: EthiopianDate = {
      ...next,
      day: Math.min(Math.max(next.day, 1), maxDay),
    };
    if (minYear != null && date.year < minYear) {
      setRejected("min");
      return;
    }
    if (maxYear != null && date.year > maxYear) {
      setRejected("max");
      return;
    }
    // The year is allowed; a month or day carried over from before moves to
    // the nearest end of the range rather than being emitted outside it.
    if (minEth && ethKey(date) < ethKey(minEth)) date = minEth;
    if (maxEth && ethKey(date) > ethKey(maxEth)) date = maxEth;
    setRejected(null);
    const greg = toGregorian(date.year, date.month, date.day);
    onChange(toIso(greg.getFullYear(), greg.getMonth() + 1, greg.getDate()));
  }

  /** The year a month or day picked into an empty field lands in. */
  function defaultYear(): number {
    let year = todayEthYear();
    if (minYear != null) year = Math.max(year, minYear);
    if (maxYear != null) year = Math.min(year, maxYear);
    return year;
  }

  const currentYear = eth?.year ?? "";
  const currentMonth = eth?.month ?? "";
  const currentDay = eth?.day ?? "";
  // Day options depend on the selected month (Pagume is 5 or 6 days).
  const dayCount = eth != null ? daysInEthiopianMonth(eth.year, eth.month) : 30;
  const optionYear = eth?.year ?? defaultYear();
  const rangeIssue: "min" | "max" | null =
    rejected ??
    (eth == null || !outOfRange(eth)
      ? null
      : minEth != null && ethKey(eth) < ethKey(minEth)
        ? "min"
        : "max");
  const rangeMessage =
    rangeIssue === "min" && minEth
      ? t("calendar.date_on_or_after", "Choose a date on or after :date", {
          date: formatEthiopian(minEth, locale),
        })
      : rangeIssue === "max" && maxEth
        ? t("calendar.date_on_or_before", "Choose a date on or before :date", {
            date: formatEthiopian(maxEth, locale),
          })
        : null;

  return (
    <div className={className}>
      {/* Wraps rather than overflows (audit N36): year and day are fixed, and
          a caller narrower than the three parts squeezed the month to an
          unreadable sliver and pushed the day out of the box. */}
      <div className="flex flex-wrap gap-2">
        <Input
          id={fieldId}
          type="number"
          inputMode="numeric"
          required={required}
          disabled={disabled}
          aria-label={partLabel(t("calendar.ethiopian_year", "Ethiopian year"))}
          // The label from FormField points at this control (it carries
          // `fieldId`), so the invalid/described wiring belongs here rather
          // than on the month or day selects beside it.
          aria-invalid={ariaInvalid || rangeIssue != null || undefined}
          aria-required={ariaRequired}
          aria-describedby={ariaDescribedBy}
          min={minYear}
          max={maxYear}
          placeholder={t("calendar.year", "Year")}
          className="w-20"
          value={yearDraft ?? currentYear}
          onChange={(e) => {
            const typed = e.target.value;
            setYearDraft(typed);
            if (!/^\d{4}$/.test(typed)) return;
            emit({
              year: Number(typed),
              month: eth?.month ?? 1,
              day: eth?.day ?? 1,
            });
          }}
          onBlur={() => {
            setYearDraft(null);
            setRejected(null);
          }}
        />
        <Select
          disabled={disabled}
          value={currentMonth === "" ? undefined : String(currentMonth)}
          onValueChange={(v) =>
            emit({
              year: optionYear,
              month: Number(v),
              day: eth?.day ?? 1,
            })
          }
        >
          <SelectTrigger
            className="min-w-28 flex-1"
            aria-label={partLabel(
              t("calendar.ethiopian_month", "Ethiopian month"),
            )}
          >
            <SelectValue placeholder={t("calendar.month", "Month")} />
          </SelectTrigger>
          <SelectContent>
            {months.map((name, i) => (
              <SelectItem
                key={name}
                value={String(i + 1)}
                disabled={monthOutside(optionYear, i + 1)}
              >
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
              year: optionYear,
              month: eth?.month ?? 1,
              day: Number(v),
            })
          }
        >
          <SelectTrigger
            className="w-20"
            aria-label={partLabel(t("calendar.ethiopian_day", "Ethiopian day"))}
          >
            <SelectValue placeholder={t("calendar.day", "Day")} />
          </SelectTrigger>
          <SelectContent>
            {Array.from({ length: dayCount }, (_, i) => i + 1).map((d) => (
              <SelectItem
                key={d}
                value={String(d)}
                disabled={
                  eth != null &&
                  outOfRange({ year: eth.year, month: eth.month, day: d })
                }
              >
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
      {rangeMessage && (
        <p role="alert" className="mt-1 text-xs text-destructive">
          {rangeMessage}
        </p>
      )}
    </div>
  );
}

function todayEthYear(): number {
  const now = new Date();
  return toEthiopian(now.getFullYear(), now.getMonth() + 1, now.getDate()).year;
}
