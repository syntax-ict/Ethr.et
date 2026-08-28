"use client";

import { Input } from "@/components/ui/input";
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
   * ARIA wiring, forwarded to the real `<input>` rather than the `+251`
   * wrapper. Without these the control could sit inside a `FormField` and still
   * be announced as valid while showing an error — the prefix span is not
   * focusable, so putting them on the wrapper announces nothing.
   */
  "aria-invalid"?: true;
  "aria-required"?: true;
  "aria-describedby"?: string;
}

/** Ethiopian phone number input with a fixed +251 prefix. */
export function PhoneInput({
  value,
  onChange,
  onBlur,
  id,
  disabled,
  required,
  className,
  "aria-invalid": ariaInvalid,
  "aria-required": ariaRequired,
  "aria-describedby": ariaDescribedBy,
}: PhoneInputProps) {
  const digits = value.startsWith("+251") ? value.slice(4) : value;

  function handleChange(e: React.ChangeEvent<HTMLInputElement>) {
    const raw = e.target.value.replace(/\D/g, "").slice(0, 9);
    onChange(raw ? `+251${raw}` : "");
  }

  return (
    <div className="flex">
      <span className="flex items-center rounded-l-md border border-r-0 border-input bg-muted px-3 text-sm text-muted-foreground">
        +251
      </span>
      <Input
        id={id}
        type="tel"
        inputMode="numeric"
        value={digits}
        onChange={handleChange}
        onBlur={onBlur}
        placeholder="9XXXXXXXX"
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
