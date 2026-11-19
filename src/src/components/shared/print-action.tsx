"use client";

import { useState } from "react";
import { ChevronDown, FileText, Loader2, Printer } from "lucide-react";
import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { useT } from "@/lib/i18n/useT";

/** A server-made PDF of the whole list, offered beside printing the page. */
export interface PrintPdf {
  /** The menu item, for example "Full register as PDF". */
  label: string;
  /**
   * Fetches and saves the file. It reports its own failure (a toast): a
   * rejection here is swallowed so the menu never throws into the page.
   */
  onDownload: () => Promise<void>;
  /** A line under the label, for what the PDF does not apply. */
  note?: string;
}

/**
 * "Print" on a page people take to paper: the attendance register, the
 * approvals queue, a profile, the organisation structure, the analytics
 * tables. It prints the page as it stands, through the print stylesheet
 * (shell hidden, tables carried across pages, the organisation and date at
 * the top). Official documents, such as payslips, letters and invoices,
 * stay generated PDFs: a document has a template; a print is a working copy.
 * The button itself is not printed.
 *
 * A list that shows one page of many can pass `pdf`: the button then opens a
 * two-item menu, "Print this page" and the server's PDF of the whole list.
 */
export function PrintAction({
  className,
  size = "default",
  pdf,
}: {
  className?: string;
  size?: "default" | "sm";
  pdf?: PrintPdf;
}) {
  const { t } = useT();
  const [open, setOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const iconClass = size === "sm" ? "mr-2 h-3 w-3" : "mr-2 h-4 w-4";
  const classes = ["no-print", className].filter(Boolean).join(" ");

  if (!pdf) {
    return (
      <Button
        type="button"
        variant="outline"
        size={size}
        className={classes}
        onClick={() => window.print()}
      >
        <Printer className={iconClass} aria-hidden="true" />
        {t("common.print", "Print")}
      </Button>
    );
  }

  async function download(run: () => Promise<void>) {
    setBusy(true);
    try {
      await run();
    } catch {
      // The caller toasts; nothing more to say here.
    } finally {
      setBusy(false);
      setOpen(false);
    }
  }

  return (
    <DropdownMenu open={open} onOpenChange={setOpen}>
      <DropdownMenuTrigger asChild>
        <Button
          type="button"
          variant="outline"
          size={size}
          className={classes}
          aria-busy={busy}
        >
          {busy ? (
            <Loader2
              className={`${iconClass} animate-spin`}
              aria-hidden="true"
            />
          ) : (
            <Printer className={iconClass} aria-hidden="true" />
          )}
          {t("common.print", "Print")}
          <ChevronDown className="ml-2 h-3 w-3" aria-hidden="true" />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="no-print">
        <DropdownMenuItem onSelect={() => window.print()}>
          <Printer className="mr-2 h-4 w-4" aria-hidden="true" />
          {t("print.this_page", "Print this page")}
        </DropdownMenuItem>
        <DropdownMenuItem
          disabled={busy}
          onSelect={(event) => {
            // Stay open while the file is made, so the spinner is seen.
            event.preventDefault();
            void download(pdf.onDownload);
          }}
        >
          {busy ? (
            <Loader2 className="mr-2 h-4 w-4 animate-spin" aria-hidden="true" />
          ) : (
            <FileText className="mr-2 h-4 w-4" aria-hidden="true" />
          )}
          <span className="flex flex-col">
            <span>{pdf.label}</span>
            {pdf.note && (
              <span className="max-w-56 text-xs text-muted-foreground">
                {pdf.note}
              </span>
            )}
          </span>
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}
