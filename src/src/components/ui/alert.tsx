import * as React from "react";
import { cva, type VariantProps } from "class-variance-authority";
import {
  AlertCircle,
  CheckCircle2,
  Info,
  type LucideIcon,
  TriangleAlert,
} from "lucide-react";
import { cn } from "@/lib/utils";

/**
 * Inline, page-level status message.
 *
 * Built on the `--color-*-soft` / `-on-soft` / `-edge` token families from
 * `styles/globals.css`, so each variant repaints correctly in light, dark and
 * high-contrast with no `dark:` variants at the call site. In high contrast the
 * soft fill collapses to the page surface and the opaque black edge carries the
 * boundary, which is exactly what those tokens are designed for.
 *
 * Use this for status that is *part of the page*. For transient confirmation of
 * a mutation, use a sonner toast instead; for a blocking question, use
 * `ConfirmDialog`.
 */
const alertVariants = cva(
  "relative flex w-full items-start gap-3 rounded-lg border px-4 py-3 text-sm",
  {
    variants: {
      variant: {
        info: "border-info-edge bg-info-soft text-info-on-soft",
        success: "border-success-edge bg-success-soft text-success-on-soft",
        warning: "border-warning-edge bg-warning-soft text-warning-on-soft",
        destructive:
          "border-destructive-edge bg-destructive-soft text-destructive-on-soft",
        neutral: "border-neutral-edge bg-neutral-soft text-neutral-on-soft",
      },
    },
    defaultVariants: {
      variant: "info",
    },
  },
);

const variantIcons: Record<string, LucideIcon> = {
  info: Info,
  success: CheckCircle2,
  warning: TriangleAlert,
  destructive: AlertCircle,
  neutral: Info,
};

export interface AlertProps
  extends
    React.HTMLAttributes<HTMLDivElement>,
    VariantProps<typeof alertVariants> {
  /** Set false to drop the leading icon (e.g. in a dense table cell). */
  icon?: boolean;
}

const Alert = React.forwardRef<HTMLDivElement, AlertProps>(
  ({ className, variant, icon = true, children, ...props }, ref) => {
    const Icon = variantIcons[variant ?? "info"] ?? Info;

    return (
      <div
        ref={ref}
        // `alert` fires an assertive live-region announcement, which is right
        // for an error the user must act on but rude for ambient info that was
        // on the page at load. Only the destructive variant claims it.
        role={variant === "destructive" ? "alert" : "status"}
        className={cn(alertVariants({ variant }), className)}
        {...props}
      >
        {icon && (
          <Icon className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
        )}
        <div className="min-w-0 flex-1">{children}</div>
      </div>
    );
  },
);
Alert.displayName = "Alert";

const AlertTitle = React.forwardRef<
  HTMLParagraphElement,
  React.HTMLAttributes<HTMLHeadingElement>
>(({ className, ...props }, ref) => (
  <p
    ref={ref}
    className={cn("mb-0.5 font-semibold leading-snug", className)}
    {...props}
  />
));
AlertTitle.displayName = "AlertTitle";

const AlertDescription = React.forwardRef<
  HTMLParagraphElement,
  React.HTMLAttributes<HTMLParagraphElement>
>(({ className, ...props }, ref) => (
  <div ref={ref} className={cn("leading-relaxed", className)} {...props} />
));
AlertDescription.displayName = "AlertDescription";

export { Alert, AlertTitle, AlertDescription, alertVariants };
