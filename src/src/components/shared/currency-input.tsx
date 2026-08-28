"use client";

import { useState } from "react";
import { Input } from "@/components/ui/input";
import { cn } from "@/lib/utils";

interface CurrencyInputProps {
  /** Value in ETB cents (BIGINT minor units), matching CLAUDE.md's integer-currency convention. */
  value: number;
  onChange: (cents: number) => void;
  id?: string;
  placeholder?: string;
  disabled?: boolean;
  required?: boolean;
  className?: string;
}

function centsToDisplay(cents: number): string {
  if (!cents) return "";
  return (cents / 100).toFixed(2);
}

/**
 * ETB-formatted currency input. Always emits/receives integer cents, never a
 * float ETB amount, so callers never need to hand-roll the *100 / /100
 * conversion (a common source of rounding bugs with monetary values).
 */
export function CurrencyInput({
  value,
  onChange,
  id,
  placeholder,
  disabled,
  required,
  className,
}: CurrencyInputProps) {
  const [text, setText] = useState(() => centsToDisplay(value));
  const [isFocused, setIsFocused] = useState(false);

  // Only resync from the prop while not focused, so an external value update
  // (e.g. a parent recomputing state after this input's own onChange) never
  // clobbers the digits the user is mid-way through typing. Adjusting state
  // during render avoids an extra effect commit — see
  // https://react.dev/learn/you-might-not-need-an-effect. The blur-time
  // resync (normalizing whatever the user typed to the canonical display
  // format) happens directly in the onBlur handler below.
  const [prevValue, setPrevValue] = useState(value);
  if (!isFocused && value !== prevValue) {
    setPrevValue(value);
    setText(centsToDisplay(value));
  }

  function handleChange(e: React.ChangeEvent<HTMLInputElement>) {
    const raw = e.target.value;
    setText(raw);

    const parsed = parseFloat(raw.replace(/,/g, ""));
    onChange(Number.isFinite(parsed) ? Math.round(parsed * 100) : 0);
  }

  return (
    <div className="relative">
      <Input
        id={id}
        type="text"
        inputMode="decimal"
        value={text}
        onChange={handleChange}
        onFocus={() => setIsFocused(true)}
        onBlur={() => {
          setIsFocused(false);
          setText(centsToDisplay(value));
        }}
        placeholder={placeholder ?? "0.00"}
        disabled={disabled}
        required={required}
        className={cn("pr-12", className)}
      />
      <span className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-sm text-muted-foreground">
        ETB
      </span>
    </div>
  );
}
