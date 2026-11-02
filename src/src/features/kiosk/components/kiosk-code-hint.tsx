"use client";

import { Button } from "@/components/ui/button";
import { useT } from "@/lib/i18n/useT";
import { fullCode, type KioskCodeFormat } from "../kiosk-code";

/**
 * Under the code field: the full code the typed number stands for, or, before
 * anything is typed, how to type it; and the key that opens the tablet's
 * keyboard for a code with letters.
 */
export function KioskCodeHint({
  code,
  format,
  letters,
  onLetters,
}: {
  code: string;
  format: KioskCodeFormat | null | undefined;
  letters: boolean;
  onLetters: () => void;
}) {
  const { t } = useT();
  const full = fullCode(code, format);
  const example = fullCode("12", format);
  const hint =
    full !== null && full !== code
      ? t("kiosk_page.code_preview", undefined, { code: full })
      : code === "" && example !== null && example !== "12"
        ? t("kiosk_page.number_hint", undefined, { code: example })
        : "";

  return (
    <div className="mt-2 flex items-center justify-center gap-3">
      <p className="min-h-5 text-sm text-muted-foreground" aria-live="polite">
        {hint}
      </p>
      {!letters && (
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="font-mono"
          title={t("kiosk_page.letters_keyboard")}
          onClick={onLetters}
        >
          ABC
          <span className="sr-only">{t("kiosk_page.letters_keyboard")}</span>
        </Button>
      )}
    </div>
  );
}
