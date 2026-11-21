"use client";

import { useEffect, useState } from "react";

import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { useT } from "@/lib/i18n/useT";

/**
 * A pick list over one of the fixed lists in `lib/options` (gender, marital
 * status, relationship, education level), so every form offers the same
 * choices in the same words.
 *
 * Nothing already saved is lost to the list:
 *  - a stored value that is not on it is shown as it was written, as an extra
 *    entry, and stays the value until the person picks something else;
 *  - with `otherValue`, that entry is "Other" and a text box beside it keeps
 *    what the person types, which is how the emergency-contact relationship
 *    still takes "Uncle" or "Neighbour".
 */
export function OptionSelect({
  id,
  value,
  onChange,
  options,
  getLabel,
  placeholder,
  otherValue,
  otherLabel,
  disabled,
  className,
}: {
  id: string;
  value: string | null | undefined;
  onChange: (value: string) => void;
  options: readonly string[];
  getLabel: (value: string) => string;
  placeholder?: string;
  /** The list entry that opens a text box for a value the list does not have. */
  otherValue?: string;
  /** What the text box beside "Other" asks for; also its accessible name. */
  otherLabel?: string;
  disabled?: boolean;
  className?: string;
}) {
  const { t } = useT();
  const current = value ?? "";
  const known = options.includes(current);
  const typed = current !== "" && !known ? current : "";
  const [custom, setCustom] = useState(typed !== "");
  const [other, setOther] = useState(typed);

  // A record that loads after the control first appears changes `value`; the
  // text box and the typed text follow it rather than keeping what the first
  // render saw.
  useEffect(() => {
    if (typed !== "") {
      setCustom(true);
      setOther(typed);
    } else if (current === "") {
      setCustom(false);
      setOther("");
    }
  }, [typed, current]);

  const offersOther = otherValue !== undefined;
  const showBox = offersOther && (custom || current === otherValue);
  const selected = offersOther && typed !== "" ? otherValue : current;
  const extra = !offersOther && typed !== "" ? typed : null;

  return (
    <div className="space-y-2">
      <Select
        value={selected}
        onValueChange={(next) => {
          if (offersOther && next === otherValue) {
            setCustom(true);
            onChange(other.trim() === "" ? next : other);
            return;
          }
          setCustom(false);
          onChange(next);
        }}
        disabled={disabled}
      >
        <SelectTrigger id={id} className={className}>
          <SelectValue
            placeholder={placeholder ?? t("common.select", "Select")}
          />
        </SelectTrigger>
        <SelectContent>
          {options.map((option) => (
            <SelectItem key={option} value={option}>
              {getLabel(option)}
            </SelectItem>
          ))}
          {extra !== null && <SelectItem value={extra}>{extra}</SelectItem>}
        </SelectContent>
      </Select>
      {showBox && (
        <Input
          aria-label={otherLabel}
          placeholder={otherLabel}
          maxLength={100}
          value={other}
          disabled={disabled}
          onChange={(event) => {
            setOther(event.target.value);
            onChange(
              event.target.value.trim() === ""
                ? (otherValue as string)
                : event.target.value,
            );
          }}
        />
      )}
    </div>
  );
}
