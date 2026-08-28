"use client";

import * as React from "react";
import { AlertCircle } from "lucide-react";

import { cn } from "@/lib/utils";

export interface FormErrorSummaryProps {
  /** Form-level message. Nothing renders when null/empty. */
  message?: string | null;
  /**
   * Optional per-field list, rendered as buttons that focus the offending
   * control. Pass RHF's `formState.errors` flattened to `{ field, message }`
   * via `summaryItems()`.
   */
  items?: readonly FormErrorItem[];
  /** Heading above the list. Supply a translated string. */
  title?: string;
  className?: string;
}

export interface FormErrorItem {
  /** The control's `id`, so the summary can move focus to it. */
  field: string;
  message: string;
  /** Human label for the field; falls back to the raw field name. */
  label?: string;
}

/**
 * The "server error summary at top" half of the Form Pattern Library rules.
 *
 * Inline field errors (`FormField`) answer "what is wrong with this input".
 * They do not answer "why did my submit fail", which is a different question
 * whenever the reason is not attached to a visible field — a plan limit, a
 * conflicting record, a dropped connection — or whenever the offending field
 * has scrolled out of view on a long form.
 *
 * `role="alert"` plus `tabIndex={-1}` and a focus on mount: a screen-reader
 * user submitting a form gets the failure announced and the caret placed at the
 * explanation, instead of being left at a submit button that quietly did
 * nothing. Sighted keyboard users get the same jump.
 *
 * Each listed field is a real `<button>` rather than an anchor: it moves focus
 * within the page and changes nothing in the URL, so a link would be a lie to
 * assistive tech and would litter the history stack.
 */
export function FormErrorSummary({
  message,
  items,
  title,
  className,
}: FormErrorSummaryProps) {
  const ref = React.useRef<HTMLDivElement>(null);
  const hasItems = (items?.length ?? 0) > 0;
  const visible = Boolean(message) || hasItems;

  React.useEffect(() => {
    if (visible) ref.current?.focus();
  }, [visible, message]);

  if (!visible) return null;

  return (
    <div
      ref={ref}
      role="alert"
      tabIndex={-1}
      className={cn(
        "rounded-lg border border-destructive-edge bg-destructive-soft p-3 outline-none focus-visible:ring-2 focus-visible:ring-ring",
        className,
      )}
    >
      <div className="flex gap-2">
        <AlertCircle
          className="mt-0.5 h-4 w-4 shrink-0 text-destructive"
          aria-hidden="true"
        />
        <div className="min-w-0 space-y-1">
          {title && (
            <p className="text-sm font-medium text-destructive-on-soft">
              {title}
            </p>
          )}
          {message && (
            <p className="text-sm text-destructive-on-soft">{message}</p>
          )}
          {hasItems && (
            <ul className="space-y-0.5 text-sm text-destructive-on-soft">
              {items!.map((item) => (
                <li key={item.field}>
                  <button
                    type="button"
                    className="text-left underline underline-offset-2 hover:no-underline"
                    onClick={() => {
                      const el = document.getElementById(item.field);
                      el?.focus();
                      el?.scrollIntoView({ block: "center" });
                    }}
                  >
                    <span className="font-medium">
                      {item.label ?? item.field}
                    </span>
                    {": "}
                    {item.message}
                  </button>
                </li>
              ))}
            </ul>
          )}
        </div>
      </div>
    </div>
  );
}

/**
 * Flatten RHF's nested `errors` object into the flat list the summary renders.
 *
 * `labels` maps a field path to its human name so the summary reads
 * "Email: must be a valid address" rather than exposing the wire field name.
 */
export function summaryItems(
  errors: Record<string, unknown>,
  labels: Record<string, string> = {},
): FormErrorItem[] {
  const out: FormErrorItem[] = [];

  const walk = (node: unknown, path: string) => {
    if (!node || typeof node !== "object") return;

    const candidate = node as { message?: unknown; type?: unknown };
    if (typeof candidate.message === "string" && candidate.message.length > 0) {
      out.push({
        field: path,
        message: candidate.message,
        label: labels[path],
      });
      return;
    }

    for (const [key, value] of Object.entries(node)) {
      walk(value, path ? `${path}.${key}` : key);
    }
  };

  walk(errors, "");
  return out;
}
