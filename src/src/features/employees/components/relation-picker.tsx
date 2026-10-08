"use client";

import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { useT } from "@/lib/i18n/useT";

/** Radix Select allows no empty value, so "none" travels as this. */
const NONE = "__none__";

/**
 * One optional relation on an employee (supervisor, team, cost centre): a
 * labelled select whose "None" clears it. `value` and `onChange` use the
 * public_id, or "" for none, which the API reads as null.
 */
export function RelationPicker({
  id,
  label,
  value,
  options,
  onChange,
}: {
  id: string;
  /** Omit when a surrounding FormField already labels the control. */
  label?: string;
  value: string | undefined;
  options: { value: string; label: string }[];
  onChange: (value: string) => void;
}) {
  const { t } = useT();

  return (
    <div>
      {label && <Label htmlFor={id}>{label}</Label>}
      <Select
        value={value ? value : NONE}
        onValueChange={(v) => onChange(v === NONE ? "" : v)}
      >
        <SelectTrigger id={id} className={label ? "mt-1" : undefined}>
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value={NONE}>{t("common.none", "None")}</SelectItem>
          {options.map((o) => (
            <SelectItem key={o.value} value={o.value}>
              {o.label}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
    </div>
  );
}
