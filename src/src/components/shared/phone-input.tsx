"use client";

import { useState } from "react";

import { Input } from "@/components/ui/input";
import { canonicalPhone, groupNationalDigits } from "@/lib/phone";
import { cn } from "@/lib/utils";

interface PhoneInputProps {
  /** Full value, e.g. "+251912345678", or "" when empty. */
  value: string;
  onChange: (value: string) => void;
  /** Fired when the control loses focus, so `onTouched` validation can run. */
  onBlur?: () => void;
  id?: string;
  disabled?: boolean;
  required?: boolean;
  className?: string;
  /**
   * Let a typed `+` and a country code switch the box to a foreign number, for
   * a field that records a person who may not be Ethiopian (an employee, an
   * emergency contact). Leave it off where a text message is sent: those only
   * reach Ethiopian mobiles.
   */
  international?: boolean;
  /**
   * ARIA wiring, forwarded to the real `<input>` rather than the `+251`
   * wrapper. Without these the control could sit inside a `FormField` and still
   * be announced as valid while showing an error — the prefix span is not
   * focusable, so putting them on the wrapper announces nothing.
   */
  "aria-invalid"?: true;
  "aria-required"?: true;
  "aria-describedby"?: string;
}

/** Longest national number we keep, and the E.164 limit for a foreign one. */
const NATIONAL_DIGITS = 9;
const INTERNATIONAL_DIGITS = 15;

const isForeign = (value: string): boolean =>
  value.startsWith("+") && !value.startsWith("+251");

/**
 * The nine digits after +251 for whatever shape the value arrives in. A stored
 * number may be `0911…` or `911…` (the server keeps an unparseable value as it
 * was given), and showing it unchanged is what used to cost a digit on the next
 * edit.
 */
function nationalDigitsOf(value: string): string {
  const canonical = canonicalPhone(value);

  if (canonical) return canonical.slice(4);

  let digits = value.replace(/\D+/g, "");

  if (digits.startsWith("251") && digits.length > NATIONAL_DIGITS) {
    digits = digits.slice(3);
  }

  return digits.replace(/^0+/, "").slice(0, NATIONAL_DIGITS);
}

/**
 * What the user typed or pasted, as the digits after +251. A leading 0 is the
 * trunk prefix and a leading 251 (with or without the plus) is the country
 * code: both are dropped, so `0911 22 33 44` and `+251 911 223 344` give the
 * same nine digits.
 */
function nationalDigitsFromTyping(raw: string): string {
  const plus = raw.trim().startsWith("+");
  let digits = raw.replace(/\D+/g, "");

  if (digits.startsWith("251") && (plus || digits.length > NATIONAL_DIGITS)) {
    digits = digits.slice(3);
  }

  return digits.replace(/^0+/, "").slice(0, NATIONAL_DIGITS);
}

/** Phone number input with a fixed +251 prefix, or a foreign number when allowed. */
export function PhoneInput({
  value,
  onChange,
  onBlur,
  id,
  disabled,
  required,
  className,
  international = false,
  "aria-invalid": ariaInvalid,
  "aria-required": ariaRequired,
  "aria-describedby": ariaDescribedBy,
}: PhoneInputProps) {
  const [focused, setFocused] = useState(false);

  const foreign = international && isForeign(value);
  const national = foreign ? "" : nationalDigitsOf(value);

  // Grouped (`91 122 3344`) only while the field is at rest: reformatting under
  // the caret as someone types moves it.
  const shown = foreign
    ? value
    : focused
      ? national
      : groupNationalDigits(national);

  function handleChange(e: React.ChangeEvent<HTMLInputElement>) {
    const raw = e.target.value;
    const typedPlus = raw.trim().startsWith("+");

    if (international && typedPlus && !raw.trim().startsWith("+251")) {
      const digits = raw.replace(/\D+/g, "").slice(0, INTERNATIONAL_DIGITS);
      onChange(`+${digits}`);
      return;
    }

    const digits = nationalDigitsFromTyping(raw);
    onChange(digits ? `+251${digits}` : "");
  }

  return (
    <div className="flex">
      {foreign ? null : (
        <span className="flex items-center rounded-l-md border border-r-0 border-input bg-muted px-3 text-sm text-muted-foreground">
          +251
        </span>
      )}
      <Input
        id={id}
        type="tel"
        inputMode={foreign ? "tel" : "numeric"}
        value={shown}
        onChange={handleChange}
        onFocus={() => setFocused(true)}
        onBlur={() => {
          setFocused(false);
          onBlur?.();
        }}
        placeholder={foreign ? "+44 20 7946 0958" : "9XXXXXXXX / 7XXXXXXXX"}
        autoComplete="tel"
        disabled={disabled}
        required={required}
        aria-invalid={ariaInvalid}
        aria-required={ariaRequired}
        aria-describedby={ariaDescribedBy}
        className={cn(foreign ? "" : "rounded-l-none", className)}
      />
    </div>
  );
}
