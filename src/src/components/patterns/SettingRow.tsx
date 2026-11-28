"use client";

import * as React from "react";
import { ChevronRight } from "lucide-react";
import { Alert } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { useT } from "@/lib/i18n/useT";
import { cn } from "@/lib/utils";

interface SettingRowProps {
  /** Stable id; the control derives its `aria-labelledby` from it. */
  id: string;
  /** A noun phrase of two to four words. The unit belongs in the control. */
  title: React.ReactNode;
  /**
   * One line: what happens when the setting is on. It is the control's
   * accessible description, so keep the law, the limits and the edge cases in
   * `details`.
   */
  description?: React.ReactNode;
  /** The law, the limits and the edge cases, behind a closed "How it works". */
  details?: React.ReactNode;
  /** Effect tag beside the title: "Writes attendance", "Affects pay". */
  badge?: React.ReactNode;
  /** Shown only when it applies: an inline warning, not a sentence of prose. */
  warning?: React.ReactNode;
  /** A validation message for the control, shown under the row. */
  error?: React.ReactNode;
  /** The control: a Switch, Select, Input, or button. */
  children: React.ReactNode;
  /** Put the control on its own row under the help, full width: selects, text inputs. */
  stacked?: boolean;
  className?: string;
}

/**
 * A closed "How it works" disclosure. A native `<details>`, so the keyboard,
 * the screen reader and the closed state need no JavaScript. Used under a
 * setting row and under a section's intro.
 */
export function HowItWorks({
  children,
  className,
}: {
  children: React.ReactNode;
  className?: string;
}) {
  const { t } = useT();
  return (
    <details className={cn("group text-caption", className)}>
      <summary className="inline-flex cursor-pointer list-none items-center gap-1 rounded-sm text-muted-foreground hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring [&::-webkit-details-marker]:hidden">
        <ChevronRight
          className="h-3.5 w-3.5 transition-transform group-open:rotate-90"
          aria-hidden="true"
        />
        {t("common.how_it_works", "How it works")}
      </summary>
      <div className="mt-1 space-y-1 pl-[18px] text-muted-foreground">
        {children}
      </div>
    </details>
  );
}

/**
 * A labelled settings row: title, one line of help, a control, and, only when
 * the setting needs them, an effect tag, a warning and a "How it works".
 *
 * Settings screens repeat the shape "bold line, muted line, control on the
 * right" dozens of times, and every instance was hand-assembled from a `div`,
 * two `p`s and a bare control. Because the visible label was a `<p>` rather
 * than a `<label>`, nothing associated the two: 13 of the app's 18 `Switch`
 * instances had no accessible name at all, which axe reports as a **critical**
 * `button-name` failure (a Radix Switch is a `role="switch"` button).
 *
 * This wires `aria-labelledby` / `aria-describedby` from the rendered text, so
 * the accessible name is always exactly the visible label and cannot drift
 * from it. Those props are cloned onto `children`, so call sites keep passing a
 * plain `<Switch />`. `aria-describedby` points at the one-line help only; the
 * details are for a reader who opens them and are not read out on every focus.
 *
 * For a *form field* — a labelled input that produces a value and can be
 * invalid — use `FormField` instead; this is for configuration rows.
 */
export function SettingRow({
  id,
  title,
  description,
  details,
  badge,
  warning,
  error,
  children,
  stacked = false,
  className,
}: SettingRowProps) {
  const titleId = `${id}-title`;
  const descId = description ? `${id}-desc` : undefined;

  const control = React.isValidElement(children)
    ? React.cloneElement(
        children as React.ReactElement<Record<string, unknown>>,
        {
          "aria-labelledby":
            (children.props as Record<string, unknown>)["aria-labelledby"] ??
            titleId,
          "aria-describedby":
            (children.props as Record<string, unknown>)["aria-describedby"] ??
            descId,
        },
      )
    : children;

  return (
    <div className={cn("space-y-2", className)}>
      <div
        className={cn(
          "grid gap-x-3 gap-y-1",
          stacked ? "grid-cols-1 gap-y-2" : "grid-cols-[minmax(0,1fr)_auto]",
        )}
      >
        <div
          className={cn(
            "flex min-w-0 flex-wrap items-center gap-2",
            !stacked && "col-start-1 row-start-1",
          )}
        >
          <p id={titleId} className="text-label text-foreground">
            {title}
          </p>
          {badge && <Badge variant="neutral">{badge}</Badge>}
        </div>
        {description && (
          <p
            id={descId}
            className={cn(
              "text-caption text-muted-foreground",
              !stacked && "col-span-2 row-start-2",
            )}
          >
            {description}
          </p>
        )}
        <div
          className={cn(
            !stacked && "col-start-2 row-start-1 self-center",
            stacked && "order-last",
          )}
        >
          {control}
        </div>
      </div>
      {error}
      {warning && <Alert variant="warning">{warning}</Alert>}
      {details && <HowItWorks>{details}</HowItWorks>}
    </div>
  );
}
