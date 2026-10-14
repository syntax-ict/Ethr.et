"use client";

import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { useT } from "@/lib/i18n/useT";

export const NATIONALITY_STATUSES = [
  "ethiopian",
  "ethiopian_origin",
  "foreign",
] as const;

export type NationalityStatus = (typeof NATIONALITY_STATUSES)[number];

export function useNationalityStatusLabel() {
  const { t } = useT();
  return (status: string | null | undefined): string =>
    ({
      ethiopian: t("employee.nationality_status_ethiopian"),
      ethiopian_origin: t("employee.nationality_status_ethiopian_origin"),
      foreign: t("employee.nationality_status_foreign"),
    })[status ?? "ethiopian"] ?? String(status);
}

/**
 * Whether the person is an Ethiopian national — a fixed choice, because it
 * decides pension coverage. The free-text nationality beside it is only the
 * country name; a typo there no longer exempts anyone.
 */
export function NationalityStatusSelect({
  id,
  value,
  onChange,
}: {
  id: string;
  value: string;
  onChange: (value: NationalityStatus) => void;
}) {
  const label = useNationalityStatusLabel();

  return (
    <Select
      value={value}
      onValueChange={(v) =>
        onChange(
          (NATIONALITY_STATUSES as readonly string[]).includes(v)
            ? (v as NationalityStatus)
            : "ethiopian",
        )
      }
    >
      <SelectTrigger id={id} className="mt-1">
        <SelectValue />
      </SelectTrigger>
      <SelectContent>
        {NATIONALITY_STATUSES.map((s) => (
          <SelectItem key={s} value={s}>
            {label(s)}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  );
}
