"use client";

import { Button } from "@/components/ui/button";
import { useT } from "@/lib/i18n/useT";

/**
 * The kiosk's number pad. Letters and dashes in a code come from the
 * tablet's own keyboard; the pad only appends digits.
 */
export function KioskKeypad({
  onDigit,
  onClear,
  onBackspace,
  disabled,
}: {
  onDigit: (digit: string) => void;
  onClear: () => void;
  onBackspace: () => void;
  disabled?: boolean;
}) {
  const { t } = useT();

  return (
    <div className="grid grid-cols-3 gap-3">
      {["1", "2", "3", "4", "5", "6", "7", "8", "9"].map((d) => (
        <Button
          key={d}
          variant="outline"
          className="h-16 text-2xl font-bold"
          onClick={() => onDigit(d)}
          disabled={disabled}
        >
          {d}
        </Button>
      ))}
      <Button
        variant="outline"
        className="h-16"
        onClick={onClear}
        disabled={disabled}
      >
        {t("kiosk_page.clear")}
      </Button>
      <Button
        variant="outline"
        className="h-16 text-2xl font-bold"
        onClick={() => onDigit("0")}
        disabled={disabled}
      >
        0
      </Button>
      <Button
        variant="outline"
        className="h-16"
        onClick={onBackspace}
        disabled={disabled}
        aria-label={t("kiosk_page.delete_digit")}
      >
        ⌫
      </Button>
    </div>
  );
}

/** Six boxes that fill with dots as a PIN is typed. */
export function PinDots({
  value,
  length = 6,
}: {
  value: string;
  length?: number;
}) {
  return (
    <div className="flex justify-center gap-2" aria-hidden="true">
      {Array.from({ length }).map((_, i) => (
        <div
          key={i}
          className={
            "h-12 w-8 rounded border-2 flex items-center justify-center text-2xl font-mono font-bold " +
            (value[i] ? "border-primary bg-primary/5" : "border-muted")
          }
        >
          {value[i] ? "•" : ""}
        </div>
      ))}
    </div>
  );
}
