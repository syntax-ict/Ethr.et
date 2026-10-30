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
import {
  branchesApi,
  costCentersApi,
  departmentsApi,
} from "@/features/organization/api";
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
 * App\Enums\AttendanceStatus, the cases a record can hold in a report.
 * `voided` is left out: a voided record is a mistake undone, not a status
 * anyone reports on.
 */
const ATTENDANCE_STATUSES = [
  "present",
  "late",
  "early_leave",
  "absent",
  "on_leave",
  "holiday",
  "pending",
] as const;

/**
 * The value control for one report filter, shaped to what ReportEngine reads
 * for that field. Every filter was a free-text box: a status typed as
 * "Active" or a date typed as "1/10/2026" matched nothing and the report came
 * back empty with no hint why (audit N100).
 */
export function ReportFilterValue({
  source,
  field,
  value,
  onChange,
  label,
}: {
  /** The report source: `status` means an employee's on one, a record's on another. */
  source: string;
  field: string;
  value: string;
  onChange: (value: string) => void;
  /** Names the control for assistive tech, e.g. "Filter value: Status". */
  label: string;
}) {
  const { t } = useT();

  if (field === "status") {
    const statuses =
      source === "attendance" ? ATTENDANCE_STATUSES : EMPLOYEE_STATUSES;
    return (
      <Select value={value || undefined} onValueChange={onChange}>
        <SelectTrigger className="h-8 flex-1" aria-label={label}>
          <SelectValue placeholder={t("reports_page.value")} />
        </SelectTrigger>
        <SelectContent>
          {statuses.map((status) => (
            <SelectItem key={status} value={status}>
              {t(`status.${status}`, status)}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
    );
  }

  if (field === "department" || field === "branch" || field === "cost_center") {
    return (
      <UnitSelect
        field={field}
        value={value}
        onChange={onChange}
        label={label}
      />
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

  if (field === "month") {
    return (
      <Input
        type="month"
        value={value}
        onChange={(e) => onChange(e.target.value)}
        aria-label={label}
        className="h-8 flex-1"
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

/**
 * A department, branch or cost centre by name. The engine takes the unit's
 * `public_id`, which nobody can type.
 */
function UnitSelect({
  field,
  value,
  onChange,
  label,
}: {
  field: "department" | "branch" | "cost_center";
  value: string;
  onChange: (value: string) => void;
  label: string;
}) {
  const { t } = useT();
  // One list, the field's own. Each `useList` is a single useQuery, so
  // switching field keeps the hook order.
  const api =
    field === "department"
      ? departmentsApi
      : field === "branch"
        ? branchesApi
        : costCentersApi;
  const units: Array<{ public_id: string; name: string }> =
    api.useList().data?.data ?? [];

  return (
    <Select value={value || undefined} onValueChange={onChange}>
      <SelectTrigger className="h-8 flex-1" aria-label={label}>
        <SelectValue placeholder={t("reports_page.value")} />
      </SelectTrigger>
      <SelectContent>
        {units.map((unit) => (
          <SelectItem key={unit.public_id} value={unit.public_id}>
            {unit.name}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  );
}
