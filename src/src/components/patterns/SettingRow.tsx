"use client";

import * as React from "react";
import { cn } from "@/lib/utils";

interface SettingRowProps {
  /** Stable id; the control derives its `aria-labelledby` from it. */
  id: string;
  title: React.ReactNode;
  description?: React.ReactNode;
  /** The control — a Switch, Select, Input, or button. */
  children: React.ReactNode;
  /** Stack the control under the text instead of beside it. */
  stacked?: boolean;
  className?: string;
}

/**
 * A labelled settings row: title, optional description, and a control.
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
 * plain `<Switch />`.
 *
 * For a *form field* — a labelled input that produces a value and can be
 * invalid — use `FormField` instead; this is for configuration rows.
 */
export function SettingRow({
  id,
  title,
  description,
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
    <div
      className={cn(
        stacked ? "space-y-2" : "flex items-center justify-between gap-4",
        className,
      )}
    >
      <div className="min-w-0">
        <p id={titleId} className="text-sm font-medium text-foreground">
          {title}
        </p>
        {description && (
          <p id={descId} className="mt-0.5 text-xs text-muted-foreground">
            {description}
          </p>
        )}
      </div>
      <div className={cn(!stacked && "shrink-0")}>{control}</div>
    </div>
  );
}
