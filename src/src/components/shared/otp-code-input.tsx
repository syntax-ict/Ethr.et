"use client";

import { useEffect, useRef } from "react";

/**
 * A row of single-digit boxes for entering a numeric verification code.
 *
 * Extracted from the MFA challenge page when the OTP sign-in flow needed the
 * identical widget — same keyboard navigation, same "paste the whole code"
 * shortcut. Typing the last digit does not auto-submit (a mistyped digit should
 * be correctable before anything is sent); pasting a full-length code does, via
 * `onPasteComplete`, since a paste is either the whole code or nothing useful.
 */
interface OtpCodeInputProps {
  length?: number;
  digits: string[];
  onChange: (digits: string[]) => void;
  /** Fired once, with the joined code, when a paste fills every box. */
  onPasteComplete?: (code: string) => void;
  disabled?: boolean;
  /** Focus the first box once, when the widget first becomes interactive. */
  autoFocus?: boolean;
}

export function OtpCodeInput({
  length = 6,
  digits,
  onChange,
  onPasteComplete,
  disabled,
  autoFocus,
}: OtpCodeInputProps) {
  const inputsRef = useRef<Array<HTMLInputElement | null>>([]);

  useEffect(() => {
    if (autoFocus) inputsRef.current[0]?.focus();
    // Intentionally empty deps: this is "on mount", not "whenever autoFocus
    // changes" — a caller flipping autoFocus later should not steal focus back.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  function setDigit(i: number, value: string) {
    const clean = value.replace(/\D/g, "").slice(0, 1);
    const next = [...digits];
    next[i] = clean;
    onChange(next);
    if (clean && i < length - 1) inputsRef.current[i + 1]?.focus();
  }

  function handleKeyDown(i: number, e: React.KeyboardEvent<HTMLInputElement>) {
    if (e.key === "Backspace" && !digits[i] && i > 0) {
      inputsRef.current[i - 1]?.focus();
    } else if (e.key === "ArrowLeft" && i > 0) {
      inputsRef.current[i - 1]?.focus();
    } else if (e.key === "ArrowRight" && i < length - 1) {
      inputsRef.current[i + 1]?.focus();
    }
  }

  function handlePaste(e: React.ClipboardEvent<HTMLInputElement>) {
    e.preventDefault();
    const pasted = e.clipboardData
      .getData("text")
      .replace(/\D/g, "")
      .slice(0, length);
    if (pasted.length === 0) return;

    const next = Array(length).fill("");
    for (let i = 0; i < pasted.length; i++) next[i] = pasted[i];
    onChange(next);

    const focusIdx = Math.min(pasted.length, length - 1);
    inputsRef.current[focusIdx]?.focus();

    if (pasted.length === length) {
      onPasteComplete?.(pasted);
    }
  }

  return (
    <div className="flex justify-center gap-2">
      {digits.map((digit, i) => (
        <input
          key={i}
          ref={(el) => {
            inputsRef.current[i] = el;
          }}
          type="text"
          inputMode="numeric"
          pattern="\d*"
          maxLength={1}
          value={digit}
          onChange={(e) => setDigit(i, e.target.value)}
          onKeyDown={(e) => handleKeyDown(i, e)}
          onPaste={i === 0 ? handlePaste : undefined}
          disabled={disabled}
          className="h-12 w-10 rounded-md border border-input bg-background text-center text-xl font-mono ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-50"
          autoComplete={i === 0 ? "one-time-code" : "off"}
        />
      ))}
    </div>
  );
}
