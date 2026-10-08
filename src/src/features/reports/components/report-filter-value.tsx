"use client";

import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { DualCalendarDateInput } from "@/components/shared/dual-calendar-date-input";
import type { components } from "@/api/generated";
import { useT } from "@/lib/i18n/useT";

type EmployeeStatus = components["schemas"]["EmployeeStatus"];

/** App\Enums\EmployeeStatus, every case: the engine filters on the raw value. */
const EMPLOYEE_STATUSES: readonly EmployeeStatus[] = [
  "hired",
  "probation",
  "confirmed",
  "suspended",
  "resigned",
  "terminated",
  "retired",
];

/**
 * The value control for one report filter, shaped to what ReportEngine reads
 * for that field. Every filter was a free-text box: a status typed as
 * "Active" or a date typed as "1/10/2026" matched nothing and the report came
 * back empty with no hint why (audit N100).
 */
export function ReportFilterValue({
  field,
  value,
  onChange,
  label,
}: {
  field: string;
  value: string;
  onChange: (value: string) => void;
  /** Names the control for assistive tech, e.g. "Filter value: Status". */
  label: string;
}) {
  const { t } = useT();

  if (field === "status") {
    return (
      <Select value={value || undefined} onValueChange={onChange}>
        <SelectTrigger className="h-8 flex-1" aria-label={label}>
          <SelectValue placeholder={t("reports_page.value")} />
        </SelectTrigger>
        <SelectContent>
          {EMPLOYEE_STATUSES.map((status) => (
            <SelectItem key={status} value={status}>
              {t(`status.${status}`, status)}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
    );
  }

  if (field === "from" || field === "to") {
    return (
      <DualCalendarDateInput
        value={value}
        onChange={onChange}
        aria-label={label}
        className="flex-1"
      />
    );
  }

  if (field === "year") {
    return (
      <Input
        type="number"
        inputMode="numeric"
        min={2000}
        max={2100}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        placeholder={String(new Date().getFullYear())}
        aria-label={label}
        className="h-8 flex-1"
      />
    );
  }

  return (
    <Input
      value={value}
      onChange={(e) => onChange(e.target.value)}
      placeholder={t("reports_page.value")}
      aria-label={label}
      className="h-8 flex-1"
    />
  );
}
