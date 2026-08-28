"use client";

import * as React from "react";
import * as ProgressPrimitive from "@radix-ui/react-progress";
import { cn } from "@/lib/utils";

type ProgressProps = React.ComponentPropsWithoutRef<
  typeof ProgressPrimitive.Root
> & {
  /**
   * Accessible name. Required — a `role="progressbar"` with no name is a
   * serious WCAG 4.1.2 failure (axe `aria-progressbar-name`), and every
   * existing call site had exactly that. Pass the label already rendered
   * beside the bar, or `labelledBy` if that label has an id.
   */
  label?: string;
  labelledBy?: string;
};

const Progress = React.forwardRef<
  React.ComponentRef<typeof ProgressPrimitive.Root>,
  ProgressProps
>(({ className, value, label, labelledBy, ...props }, ref) => (
  <ProgressPrimitive.Root
    ref={ref}
    aria-label={labelledBy ? undefined : label}
    aria-labelledby={labelledBy}
    // Radix sets valuemin/valuemax but leaves the text alternative empty;
    // a screen reader otherwise announces "0 percent" for an indeterminate bar.
    aria-valuenow={value ?? undefined}
    aria-valuetext={value != null ? `${Math.round(value)}%` : undefined}
    className={cn(
      "relative h-2 w-full overflow-hidden rounded-full bg-secondary",
      className,
    )}
    {...props}
  >
    <ProgressPrimitive.Indicator
      className="h-full w-full flex-1 bg-primary transition-all"
      style={{ transform: `translateX(-${100 - (value || 0)}%)` }}
    />
  </ProgressPrimitive.Root>
));
Progress.displayName = ProgressPrimitive.Root.displayName;

export { Progress };
