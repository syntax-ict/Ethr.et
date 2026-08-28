import * as React from "react";
import { cva, type VariantProps } from "class-variance-authority";
import { cn } from "@/lib/utils";

const badgeVariants = cva(
  "inline-flex items-center rounded-md border px-2.5 py-0.5 text-xs font-semibold transition-colors focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2",
  {
    variants: {
      variant: {
        default: "border-transparent bg-primary text-primary-foreground shadow",
        secondary: "border-transparent bg-secondary text-secondary-foreground",
        destructive:
          "border-transparent bg-destructive text-destructive-foreground shadow",
        outline: "text-foreground",
        // Soft-container token families rather than `bg-status-x/15
        // text-status-x`. Tinting a colour to 15% and then setting text in the
        // *same* colour is contrast-neutral at best: the pair measured 3.4:1 in
        // light mode. The `-soft` / `-on-soft` / `-edge` families are designed
        // as legible pairs and are already verified across all three themes,
        // including high contrast where the tint collapses to the page surface
        // and the opaque edge carries the boundary.
        success: "border-success-edge bg-success-soft text-success-on-soft",
        warning: "border-warning-edge bg-warning-soft text-warning-on-soft",
        info: "border-info-edge bg-info-soft text-info-on-soft",
        neutral: "border-neutral-edge bg-neutral-soft text-neutral-on-soft",
      },
    },
    defaultVariants: {
      variant: "default",
    },
  },
);

export interface BadgeProps
  extends
    React.HTMLAttributes<HTMLDivElement>,
    VariantProps<typeof badgeVariants> {}

function Badge({ className, variant, ...props }: BadgeProps) {
  return (
    <div className={cn(badgeVariants({ variant }), className)} {...props} />
  );
}

export { Badge, badgeVariants };
