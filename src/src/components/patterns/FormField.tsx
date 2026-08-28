"use client";

import * as React from "react";
import { Label } from "@/components/ui/label";
import { cn } from "@/lib/utils";

/**
 * The wiring a control needs to be announced correctly. Handed to a function
 * child so a control nested inside decoration still gets it.
 */
export interface FormControlProps {
  id: string;
  "aria-invalid"?: true;
  "aria-required"?: true;
  "aria-describedby"?: string;
}

interface FormFieldProps {
  /** Must match the `id` of the control rendered by `children`. */
  id: string;
  label: React.ReactNode;
  /** Renders the required marker and sets `aria-required` on the control. */
  required?: boolean;
  /** Persistent helper text. Announced before the error when both are present. */
  hint?: React.ReactNode;
  /** Validation message. Presence switches the field into its invalid state. */
  error?: string;
  className?: string;
  /**
   * A single control (cloned, wiring applied automatically), or a function
   * receiving the wiring to spread onto the real control.
   *
   * The function form exists for composite fields — an input sharing a bordered
   * box with a suffix (`acme` + `.ethr.et`), a currency prefix, an input with a
   * trailing button. Cloning the wrapper there would put `id` and
   * `aria-invalid` on a `<div>`: the label would point at nothing focusable and
   * the error would not be announced, which is the exact WCAG 3.3.1 gap this
   * component was built to close. Every such field in this codebase previously
   * hand-rolled its own markup and got it subtly wrong.
   */
  children: React.ReactNode | ((control: FormControlProps) => React.ReactNode);
}

/**
 * Label + control + hint + error, with the ARIA wiring done once.
 *
 * Before this existed `aria-invalid` appeared in zero of the app's components
 * and `aria-describedby` in two: labels were associated via `htmlFor`, but a
 * failed field was announced to a screen reader exactly like a valid one, and
 * server-side messages went to a toast that was never linked to any input
 * (WCAG 3.3.1 Error Identification and 1.3.1 Info and Relationships).
 *
 * `children` is cloned so the control inherits `id`, `aria-invalid`,
 * `aria-required` and an `aria-describedby` pointing at the hint and error
 * nodes — call sites keep passing a plain `<Input />` and get the wiring for
 * free. Any of those props set explicitly on the child win, so a control with
 * special needs can opt out.
 *
 * The error node is `role="alert"` so it is announced when it appears after a
 * submit, and is rendered in `--color-destructive` — never colour alone, since
 * the text itself carries the message (WCAG 1.4.1).
 */
export function FormField({
  id,
  label,
  required = false,
  hint,
  error,
  className,
  children,
}: FormFieldProps) {
  const hintId = hint ? `${id}-hint` : undefined;
  const errorId = error ? `${id}-error` : undefined;
  const describedBy = [hintId, errorId].filter(Boolean).join(" ") || undefined;

  const controlProps: FormControlProps = {
    id,
    "aria-invalid": error ? true : undefined,
    "aria-required": required || undefined,
    "aria-describedby": describedBy,
  };

  const control =
    typeof children === "function"
      ? children(controlProps)
      : React.isValidElement(children)
        ? React.cloneElement(
            children as React.ReactElement<Record<string, unknown>>,
            {
              id: (children.props as Record<string, unknown>).id ?? id,
              "aria-invalid":
                (children.props as Record<string, unknown>)["aria-invalid"] ??
                controlProps["aria-invalid"],
              "aria-required":
                (children.props as Record<string, unknown>)["aria-required"] ??
                controlProps["aria-required"],
              "aria-describedby":
                (children.props as Record<string, unknown>)[
                  "aria-describedby"
                ] ?? describedBy,
            },
          )
        : children;

  return (
    <div className={cn("space-y-1.5", className)}>
      {/* The required marker is a *sibling* of the label, not a child of it.
          Inside, it becomes part of the label's text content — so the field's
          name reads "Name *" to anything matching on text, including
          `getByLabelText` in tests and some assistive tech that falls back to
          text content rather than the accessible-name computation that
          `aria-hidden` governs. Requiredness is already conveyed properly by
          `aria-required` on the control; this asterisk is purely visual. */}
      <div className="flex items-center gap-0.5">
        <Label htmlFor={id}>{label}</Label>
        {required && (
          <span className="text-destructive" aria-hidden="true">
            *
          </span>
        )}
      </div>

      {control}

      {hint && (
        <p id={hintId} className="text-xs text-muted-foreground">
          {hint}
        </p>
      )}

      {error && (
        <p
          id={errorId}
          role="alert"
          className="text-xs font-medium text-destructive"
        >
          {error}
        </p>
      )}
    </div>
  );
}
