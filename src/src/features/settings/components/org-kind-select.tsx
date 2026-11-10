"use client";

import {
  Select,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectLabel,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { useT } from "@/lib/i18n/useT";
import type { OrganizationTypeOption } from "../api";
import { groupOrgKinds, orgKindGroupLabel, orgKindName } from "../org-kind";

/**
 * The kind of organisation in law, grouped by sector (private, non-profit,
 * public, international). The kind decides which statutory payroll rules
 * apply, so it is the same control everywhere it is asked.
 */
export function OrgKindSelect({
  id,
  kinds,
  value,
  onChange,
  disabled,
  describedBy,
}: {
  id: string;
  kinds: OrganizationTypeOption[];
  value: string | undefined;
  onChange: (code: string) => void;
  disabled?: boolean;
  describedBy?: string;
}) {
  const { t, locale } = useT();

  return (
    <Select value={value ?? ""} onValueChange={onChange} disabled={disabled}>
      <SelectTrigger id={id} aria-describedby={describedBy}>
        <SelectValue
          placeholder={t("settings.org_kind_choose", "Choose one")}
        />
      </SelectTrigger>
      <SelectContent>
        {groupOrgKinds(kinds).map((section) => (
          <SelectGroup key={section.group || "other"}>
            {section.group !== "" && (
              <SelectLabel>{orgKindGroupLabel(section.group, t)}</SelectLabel>
            )}
            {section.kinds.map((kind) => (
              <SelectItem key={kind.code} value={kind.code}>
                {orgKindName(kind, locale)}
              </SelectItem>
            ))}
          </SelectGroup>
        ))}
      </SelectContent>
    </Select>
  );
}
