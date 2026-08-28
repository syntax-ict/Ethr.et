"use client";

import * as React from "react";
import * as DialogPrimitive from "@radix-ui/react-dialog";
import { X } from "lucide-react";
import { useT } from "@/lib/i18n/useT";
import { cn } from "@/lib/utils";

interface SheetProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** Accessible name for the panel. Required — this is a modal dialog. */
  title: string;
  /** Render the title visibly. Off by default: the nav sheets are titled for
   *  assistive tech only, since the panel's contents are self-evident on screen. */
  showTitle?: boolean;
  description?: string;
  side?: "left" | "right";
  className?: string;
  children: React.ReactNode;
}

/**
 * Slide-in modal panel — the app's primary mobile navigation.
 *
 * Rebuilt on `@radix-ui/react-dialog`. The previous implementation was a plain
 * pair of divs, which meant the mobile nav — the only way to move around the
 * app on a phone — had:
 *
 *   - no focus trap (Tab walked straight out into the page behind the overlay),
 *   - no focus restoration on close (keyboard users lost their place entirely),
 *   - no initial focus move (focus stayed on the trigger, behind the overlay),
 *   - no `role="dialog"` or `aria-modal`, so screen readers never announced it
 *     as a modal and the page behind it was never hidden from the reader,
 *   - no Escape-to-close — only a click on an overlay `<div>` that carried an
 *     `onClick` with no keyboard equivalent,
 *   - a raw `document.body.style.overflow` lock with no scrollbar-width
 *     compensation, so the page jumped sideways on open.
 *
 * Radix supplies every one of those. `title` is mandatory rather than optional
 * because a modal without an accessible name fails WCAG 4.1.2, and making it
 * optional is how that regresses.
 */
function Sheet({
  open,
  onOpenChange,
  title,
  showTitle = false,
  description,
  side = "left",
  className,
  children,
}: SheetProps) {
  const { t } = useT();

  return (
    <DialogPrimitive.Root open={open} onOpenChange={onOpenChange}>
      <DialogPrimitive.Portal>
        <DialogPrimitive.Overlay className="fixed inset-0 z-50 bg-black/80 data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0" />
        <DialogPrimitive.Content
          className={cn(
            "fixed inset-y-0 z-50 flex w-72 max-w-[85vw] flex-col overflow-y-auto bg-background shadow-lg transition ease-in-out data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:duration-200 data-[state=open]:duration-300",
            side === "left"
              ? "left-0 border-r data-[state=closed]:slide-out-to-left data-[state=open]:slide-in-from-left"
              : "right-0 border-l data-[state=closed]:slide-out-to-right data-[state=open]:slide-in-from-right",
            className,
          )}
        >
          {showTitle ? (
            <DialogPrimitive.Title className="px-6 pt-6 text-lg font-semibold tracking-tight">
              {title}
            </DialogPrimitive.Title>
          ) : (
            <DialogPrimitive.Title className="sr-only">
              {title}
            </DialogPrimitive.Title>
          )}

          {description ? (
            <DialogPrimitive.Description className="px-6 text-sm text-muted-foreground">
              {description}
            </DialogPrimitive.Description>
          ) : (
            // Radix warns when a dialog has neither a description nor an
            // explicit opt-out; nav panels genuinely have nothing to add.
            <DialogPrimitive.Description className="sr-only">
              {title}
            </DialogPrimitive.Description>
          )}

          <DialogPrimitive.Close className="absolute right-4 top-4 rounded-sm opacity-70 transition-opacity hover:opacity-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
            <X className="h-4 w-4" aria-hidden="true" />
            <span className="sr-only">{t("common.close", "Close")}</span>
          </DialogPrimitive.Close>

          {children}
        </DialogPrimitive.Content>
      </DialogPrimitive.Portal>
    </DialogPrimitive.Root>
  );
}

export { Sheet };
