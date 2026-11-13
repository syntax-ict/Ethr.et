"use client";

import { useState } from "react";
import { Button } from "@/components/ui/button";
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
import {
  groupOrgKinds,
  narrowOrgKinds,
  orgKindGroupLabel,
  orgKindName,
} from "../org-kind";

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
  sector,
  keep,
}: {
  id: string;
  kinds: OrganizationTypeOption[];
  value: string | undefined;
  onChange: (code: string) => void;
  disabled?: boolean;
  describedBy?: string;
  /** The industry base: kinds suggested for it are offered first. */
  sector?: string | null;
  /** A kind that stays offered whatever the sector; defaults to `value`. */
  keep?: string | null;
}) {
  const { t, locale } = useT();
  const [showAll, setShowAll] = useState(false);
  const narrowedKinds = narrowOrgKinds(kinds, sector, keep ?? value);
  // The value in hand is always selectable, even one picked outside the sector.
  const held = value
    ? narrowedKinds.rest.find((kind) => kind.code === value)
    : undefined;
  const suggested = held
    ? kinds.filter(
        (kind) => kind === held || narrowedKinds.suggested.includes(kind),
      )
    : narrowedKinds.suggested;
  const rest = held
    ? narrowedKinds.rest.filter((kind) => kind !== held)
    : narrowedKinds.rest;
  const narrowed = rest.length > 0 && !showAll;

  return (
    <>
      <Select value={value ?? ""} onValueChange={onChange} disabled={disabled}>
        <SelectTrigger id={id} aria-describedby={describedBy}>
          <SelectValue
            placeholder={t("settings.org_kind_choose", "Choose one")}
          />
        </SelectTrigger>
        <SelectContent>
          {groupOrgKinds(narrowed ? suggested : kinds).map((section) => (
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
      {rest.length > 0 && (
        <Button
          type="button"
          variant="link"
          className="h-auto px-0 text-sm"
          onClick={() => setShowAll((all) => !all)}
        >
          {narrowed
            ? t(
                "settings.org_kind_show_all",
                "Show every kind of organisation in law",
              )
            : t(
                "settings.org_kind_show_suggested",
                "Show only the kinds that match your type of organisation",
              )}
        </Button>
      )}
    </>
  );
}
