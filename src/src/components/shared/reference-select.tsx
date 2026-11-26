"use client";

import { useState } from "react";
import { Check, ChevronsUpDown } from "lucide-react";

import { Button } from "@/components/ui/button";
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from "@/components/ui/command";
import { Input } from "@/components/ui/input";
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover";
import { Skeleton } from "@/components/ui/skeleton";
import {
  referenceName,
  useLookups,
  type ReferenceItem,
  type ReferenceKind,
  type ReferenceValue,
} from "@/features/reference/api";
import { useT } from "@/lib/i18n/useT";
import { cn } from "@/lib/utils";

interface ReferenceSelectProps {
  /** Which list: regions, the sub-cities of Addis Ababa, towns or banks. */
  kind: ReferenceKind;
  value: ReferenceValue;
  onChange: (value: ReferenceValue) => void;
  /** Narrow a sub-city or town list to one region's code. */
  parentCode?: string | null;
  /** Offer "Other" so a name that is not listed can be typed. On by default. */
  allowOther?: boolean;
  /** Offer "Clear" while something is chosen, for a field that may be left empty. */
  clearable?: boolean;
  id?: string;
  disabled?: boolean;
  className?: string;
  "aria-invalid"?: true;
  "aria-required"?: true;
  "aria-describedby"?: string;
}

/** Static, so the i18n gate can see every key. */
const PLACEHOLDER_KEYS: Record<ReferenceKind, [string, string]> = {
  region: ["reference.select_region", "Select region"],
  sub_city: ["reference.select_sub_city", "Select sub-city"],
  town: ["reference.select_town", "Select town"],
  bank: ["reference.select_bank", "Select bank"],
};

/**
 * Choose a region, sub-city, town or bank from the shared list, or type one
 * that is not on it.
 *
 * The value is `{ code, text }`: the list entry's code, and the text beside
 * it, which is what a record keeps if the list changes. Three things keep a
 * form from being blocked or from losing what was saved:
 *
 *  - "Other" turns the field into a text box, and typed text is stored with no
 *    code;
 *  - text already on a record that matches no entry is shown as typed, not
 *    blanked or overwritten;
 *  - if the list cannot be loaded the field is a text box, so the form still
 *    saves.
 *
 * Search matches the English, Amharic and Afaan Oromoo names at once, whichever
 * language the page is in.
 */
export function ReferenceSelect({
  kind,
  value,
  onChange,
  parentCode,
  allowOther = true,
  clearable = false,
  id,
  disabled,
  className,
  "aria-invalid": ariaInvalid,
  "aria-required": ariaRequired,
  "aria-describedby": ariaDescribedBy,
}: ReferenceSelectProps) {
  const { t, locale } = useT();
  const lookups = useLookups();
  const [open, setOpen] = useState(false);
  // Text with no code is shown as typed: either the person chose Other, or the
  // record held text before there was a list.
  const [typing, setTyping] = useState(
    value.code === null && value.text.trim() !== "",
  );

  const [placeholderKey, placeholderFallback] = PLACEHOLDER_KEYS[kind];
  const placeholder = t(placeholderKey, placeholderFallback);

  const textBox = (
    <Input
      id={id}
      value={value.text}
      onChange={(e) => onChange({ code: null, text: e.target.value })}
      placeholder={t("reference.type_here", "Type it here")}
      // A list with nowhere to keep typed text cannot fall back to typing:
      // the box is shown, but locked.
      disabled={disabled || (lookups.isError && !allowOther)}
      aria-invalid={ariaInvalid}
      aria-required={ariaRequired}
      aria-describedby={ariaDescribedBy}
      className={className}
    />
  );

  if (lookups.isError) return textBox;

  if (lookups.isLoading || !lookups.data) {
    return <Skeleton className="h-10 w-full" aria-hidden="true" />;
  }

  const all = lookups.data.items?.[kind] ?? [];

  if (typing && allowOther) {
    return (
      <div className="space-y-1">
        {textBox}
        <Button
          type="button"
          variant="link"
          size="sm"
          className="h-auto p-0 text-xs"
          disabled={disabled}
          onClick={() => {
            setTyping(false);
            onChange({ code: null, text: "" });
          }}
        >
          {t("reference.back_to_list", "Choose from the list")}
        </Button>
      </div>
    );
  }

  const choices =
    parentCode == null ? all : all.filter((i) => i.parent_code === parentCode);
  const selected = value.code
    ? (all.find((i) => i.code === value.code) ?? null)
    : null;
  const label = selected
    ? referenceName(selected, locale)
    : value.text.trim() !== ""
      ? value.text
      : "";

  function pick(item: ReferenceItem) {
    // The English name is what is kept beside the code, so a printed record
    // reads the same whichever language it was entered in.
    onChange({ code: item.code, text: item.name });
    setOpen(false);
  }

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          id={id}
          type="button"
          variant="outline"
          role="combobox"
          aria-expanded={open}
          aria-invalid={ariaInvalid}
          aria-required={ariaRequired}
          aria-describedby={ariaDescribedBy}
          disabled={disabled}
          className={cn("w-full justify-between px-3 font-normal", className)}
        >
          <span
            className={cn("truncate", label === "" && "text-muted-foreground")}
          >
            {label === "" ? placeholder : label}
          </span>
          <ChevronsUpDown
            className="ml-2 h-4 w-4 shrink-0 opacity-50"
            aria-hidden="true"
          />
        </Button>
      </PopoverTrigger>
      <PopoverContent
        align="start"
        className="w-[var(--radix-popover-trigger-width)] min-w-64 p-0"
      >
        <Command>
          <CommandInput placeholder={t("reference.search", "Search...")} />
          <CommandList>
            <CommandEmpty>
              {t("reference.empty", "No match. Choose Other to type it.")}
            </CommandEmpty>
            <CommandGroup>
              {clearable &&
                (value.code !== null || value.text.trim() !== "") && (
                  <CommandItem
                    // Not a place name, so a search never finds it by accident;
                    // `forceMount` keeps it at hand while one is typed.
                    forceMount
                    value="__clear__"
                    onSelect={() => {
                      setTyping(false);
                      setOpen(false);
                      onChange({ code: null, text: "" });
                    }}
                  >
                    <span className="h-4 w-4" aria-hidden="true" />
                    <span className="truncate">
                      {t("reference.clear", "Clear")}
                    </span>
                  </CommandItem>
                )}
              {choices.map((item) => (
                <CommandItem
                  key={item.code}
                  // Every language's spelling, so a search finds it however it
                  // is typed.
                  value={`${item.name} ${item.name_am} ${item.name_om ?? ""} ${item.code}`}
                  onSelect={() => pick(item)}
                >
                  <Check
                    className={cn(
                      "h-4 w-4",
                      value.code === item.code ? "opacity-100" : "opacity-0",
                    )}
                    aria-hidden="true"
                  />
                  <span className="truncate">
                    {referenceName(item, locale)}
                  </span>
                </CommandItem>
              ))}
              {allowOther && (
                <CommandItem
                  // Always listed, even when a search matches nothing: that is
                  // when it is needed.
                  forceMount
                  value="__other__"
                  onSelect={() => {
                    setTyping(true);
                    setOpen(false);
                    onChange({ code: null, text: "" });
                  }}
                >
                  <span className="h-4 w-4" aria-hidden="true" />
                  <span className="truncate">
                    {t("reference.other", "Other (type it)")}
                  </span>
                </CommandItem>
              )}
            </CommandGroup>
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  );
}
