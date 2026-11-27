"use client";

import { Copy } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { useT } from "@/lib/i18n/useT";

/**
 * A small button that copies one value typed on a terminal, so the
 * administrator never retypes a serial number or an address by eye. The label
 * names what is copied, so a screen reader hears which of the buttons is which.
 */
export function CopyValueButton({
  value,
  label,
}: {
  value: string;
  label: string;
}) {
  const { t } = useT();

  async function copy() {
    try {
      await navigator.clipboard.writeText(value);
      toast.success(t("device_token.copied"));
    } catch {
      toast.error(t("devices_page.copy_failed"));
    }
  }

  return (
    <Button
      type="button"
      variant="outline"
      size="icon"
      className="h-6 w-6 shrink-0"
      aria-label={t("device_wizard.copy_value", undefined, { label })}
      onClick={() => void copy()}
    >
      <Copy className="h-3 w-3" aria-hidden="true" />
    </Button>
  );
}
