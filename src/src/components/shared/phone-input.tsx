"use client";

import { Input } from "@/components/ui/input";
import { cn } from "@/lib/utils";
import { useT } from "@/lib/i18n/useT";

interface PhoneInputProps {
  /** Full value, e.g. "+251912345678", or "" when empty. */
  value: string;
  onChange: (value: string) => void;
  /** Fired when the control loses focus, so `onTouched` validation can run. */
  onBlur?: () => void;
  id?: string;
  name?: string;
  disabled?: boolean;
  required?: boolean;
  className?: string;
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

/**
 * Reduce typed or pasted text to the nine national digits. A pasted number
 * arrives as 0912345678, +251912345678 or 251912345678; cutting the first nine
 * digits of those kept the prefix and lost the end of the number.
 */
function nationalDigits(text: string): string {
  let d = text.replace(/\D/g, "");
  if (d.startsWith("251") && d.length === 12) d = d.slice(3);
  else if (d.startsWith("0") && d.length === 10) d = d.slice(1);
  const raw = d.slice(0, 9);
  return raw ? `+251${raw}` : "";
}

/** Ethiopian phone number input with a fixed +251 prefix. */
export function PhoneInput({
  value,
  onChange,
  onBlur,
  id,
  name,
  disabled,
  required,
  className,
  "aria-invalid": ariaInvalid,
  "aria-required": ariaRequired,
  "aria-describedby": ariaDescribedBy,
}: PhoneInputProps) {
  const { t } = useT();
  const digits = value.startsWith("+251") ? value.slice(4) : value;

  function handleChange(e: React.ChangeEvent<HTMLInputElement>) {
    onChange(nationalDigits(e.target.value));
  }

  return (
    <div className="flex">
      <span className="flex items-center rounded-l-md border border-r-0 border-input bg-muted px-3 text-sm text-muted-foreground">
        +251
      </span>
      <Input
        id={id}
        name={name}
        type="tel"
        inputMode="numeric"
        autoComplete="tel-national"
        spellCheck={false}
        value={digits}
        onChange={handleChange}
        onBlur={onBlur}
        placeholder={t("phone_input.placeholder", "911234567…")}
        disabled={disabled}
        required={required}
        aria-invalid={ariaInvalid}
        aria-required={ariaRequired}
        aria-describedby={ariaDescribedBy}
        className={cn("rounded-l-none", className)}
      />
    </div>
  );
}
