import * as React from "react";
import { cn } from "@/lib/utils";

const Input = React.forwardRef<
  HTMLInputElement,
  React.InputHTMLAttributes<HTMLInputElement>
>(({ className, type, ...props }, ref) => {
  return (
    <input
      type={type}
      className={cn(
        "flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors file:border-0 file:bg-transparent file:text-sm file:font-medium file:text-foreground placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50",
        // Invalid state is driven off `aria-invalid` so the visual and the
        // assistive-tech signal can never drift apart — styling an "error"
        // class independently is how you end up with a red border that no
        // screen reader knows about.
        // Arbitrary variant, not bare `aria-invalid:` — Tailwind v4's built-in
        // aria list is busy/checked/disabled/expanded/hidden/pressed/readonly/
        // required/selected, so the bare form compiles to nothing.
        "aria-[invalid=true]:border-destructive aria-[invalid=true]:focus-visible:ring-destructive",
        className,
      )}
      ref={ref}
      {...props}
    />
  );
});
Input.displayName = "Input";

export { Input };
