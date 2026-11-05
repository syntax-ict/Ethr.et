"use client";

import { Printer } from "lucide-react";
import { Button } from "@/components/ui/button";
import { useT } from "@/lib/i18n/useT";

/**
 * "Print" on a page people take to paper: the attendance register, the
 * approvals queue, a profile, the organisation structure, the analytics
 * tables. It prints the page as it stands, through the print stylesheet
 * (shell hidden, tables carried across pages, the organisation and date at
 * the top). Official documents, such as payslips, letters and invoices,
 * stay generated PDFs: a document has a template; a print is a working copy.
 * The button itself is not printed.
 */
export function PrintAction({
  className,
  size = "default",
}: {
  className?: string;
  size?: "default" | "sm";
}) {
  const { t } = useT();

  return (
    <Button
      type="button"
      variant="outline"
      size={size}
      className={["no-print", className].filter(Boolean).join(" ")}
      onClick={() => window.print()}
    >
      <Printer
        className={size === "sm" ? "mr-2 h-3 w-3" : "mr-2 h-4 w-4"}
        aria-hidden="true"
      />
      {t("common.print", "Print")}
    </Button>
  );
}
