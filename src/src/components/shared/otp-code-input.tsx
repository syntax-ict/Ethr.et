"use client";

import { useEffect, useRef, useState } from "react";
import { cn } from "@/lib/utils";

/**
 * A numeric verification-code field: ONE real input, drawn as a row of cells.
 *
 * It was six one-character inputs, which meant SMS and email autofill (which
 * writes the whole code into the single field flagged `one-time-code`) and a
 * paste anywhere but the first box delivered one digit and lost the rest. A
 * single input takes both. The cells are an `aria-hidden` mirror of what was
 * typed, so the control is one labelled field for a screen reader.
 *
 * Typing the last digit does not auto-submit (a mistyped digit should be
 * correctable before anything is sent); a paste or autofill that supplies the
 * whole code does, via `onPasteComplete`, since that is either the whole code
 * or nothing useful.
 */
interface OtpCodeInputProps {
  length?: number;
  digits: string[];
  onChange: (digits: string[]) => void;
  /** Fired once, with the joined code, when a paste or autofill fills every cell. */
  onPasteComplete?: (code: string) => void;
  disabled?: boolean;
  /** Focus the field once, when the widget first becomes interactive. */
  autoFocus?: boolean;
  /** Point a `<Label htmlFor>` at this id so the field has an accessible name. */
  id?: string;
  name?: string;
  "aria-label"?: string;
  "aria-labelledby"?: string;
}

function toDigits(code: string, length: number): string[] {
  return Array.from({ length }, (_, i) => code[i] ?? "");
}

export function OtpCodeInput({
  length = 6,
  digits,
  onChange,
  onPasteComplete,
  disabled,
  autoFocus,
  id,
  name,
  "aria-label": ariaLabel,
  "aria-labelledby": ariaLabelledBy,
}: OtpCodeInputProps) {
  const inputRef = useRef<HTMLInputElement>(null);
  const [focused, setFocused] = useState(false);
  const code = digits.join("").slice(0, length);

  useEffect(() => {
    if (autoFocus) inputRef.current?.focus();
    // Intentionally empty deps: this is "on mount", not "whenever autoFocus
    // changes" — a caller flipping autoFocus later should not steal focus back.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  function commit(raw: string, whole: boolean) {
    const next = raw.replace(/\D/g, "").slice(0, length);
    onChange(toDigits(next, length));
    if (whole && next.length === length) onPasteComplete?.(next);
  }

  const activeCell = Math.min(code.length, length - 1);

  return (
    <div className="relative mx-auto flex w-fit justify-center gap-2">
      {Array.from({ length }, (_, i) => (
        <div
          key={i}
          aria-hidden="true"
          className={cn(
            "flex h-12 w-10 items-center justify-center rounded-md border border-input bg-background font-mono text-xl",
            disabled && "opacity-50",
            focused && i === activeCell && "ring-2 ring-ring",
          )}
        >
          {code[i] ?? ""}
        </div>
      ))}
      <input
        ref={inputRef}
        id={id}
        name={name}
        type="text"
        inputMode="numeric"
        autoComplete="one-time-code"
        pattern="[0-9]*"
        maxLength={length}
        spellCheck={false}
        autoCapitalize="none"
        value={code}
        disabled={disabled}
        aria-label={ariaLabel}
        aria-labelledby={ariaLabelledBy}
        onChange={(e) => {
          // A jump of more than one digit is an autofill or a paste the
          // browser handled itself, not a keystroke.
          const incoming = e.target.value.replace(/\D/g, "");
          commit(incoming, incoming.length - code.length > 1);
        }}
        onPaste={(e) => {
          e.preventDefault();
          commit(e.clipboardData.getData("text"), true);
        }}
        onFocus={() => setFocused(true)}
        onBlur={() => setFocused(false)}
        className="absolute inset-0 h-full w-full cursor-text bg-transparent text-transparent caret-transparent opacity-0 disabled:cursor-not-allowed"
      />
    </div>
  );
}
