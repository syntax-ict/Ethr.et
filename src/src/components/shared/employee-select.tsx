"use client";

import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { useEmployeeOptions } from "@/features/attendance/api";
import { useT } from "@/lib/i18n/useT";

/**
 * Choose an employee by name and code; the value is their `public_id`.
 *
 * Loans and cost-sharing asked HR to paste the `public_id` itself, an
 * identifier no screen shows, so an obligation could only be recorded by
 * copying it out of a URL (2026-10-09). Manual attendance had this picker
 * inline; it lives here now so the three forms share it.
 */
export function EmployeeSelect({
  id,
  value,
  onChange,
  enabled = true,
}: {
  id?: string;
  value: string;
  onChange: (publicId: string) => void;
  /** Fetch the list only while the form is open. */
  enabled?: boolean;
}) {
  const { t } = useT();
  const { data: employees } = useEmployeeOptions({ enabled });

  return (
    <Select value={value} onValueChange={onChange}>
      <SelectTrigger id={id} className="mt-1">
        <SelectValue
          placeholder={t("attendance.select_employee", "Select employee")}
        />
      </SelectTrigger>
      <SelectContent>
        {employees?.map((e) => (
          <SelectItem key={e.public_id} value={e.public_id}>
            {e.name}{" "}
            {e.employee_code && (
              <span className="text-muted-foreground">({e.employee_code})</span>
            )}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  );
}
