import * as React from "react";
import { Input } from "@/components/ui/input";
import { cn } from "@/lib/utils";

interface InputWithUnitProps extends React.ComponentPropsWithoutRef<
  typeof Input
> {
  /** The visible unit, already translated: min, %, m, days, s. */
  unit: string;
}

/**
 * An input with its unit as a visible suffix, so a label never carries
 * "(minutes)". The unit is linked through `aria-describedby`, so a screen
 * reader hears "120, min" with the field, and any description the caller
 * passes stays linked too.
 */
export const InputWithUnit = React.forwardRef<
  HTMLInputElement,
  InputWithUnitProps
>(({ unit, className, id, inputMode = "numeric", ...props }, ref) => {
  const generated = React.useId();
  const unitId = `${id ?? generated}-unit`;
  const describedBy = [props["aria-describedby"], unitId]
    .filter(Boolean)
    .join(" ");
  return (
    <div className={cn("relative w-32", className)}>
      <Input
        {...props}
        ref={ref}
        id={id}
        inputMode={inputMode}
        aria-describedby={describedBy}
        className="pr-12 [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
      />
      <span
        id={unitId}
        className="pointer-events-none absolute inset-y-0 right-3 flex items-center text-caption text-muted-foreground"
      >
        {unit}
      </span>
    </div>
  );
});
InputWithUnit.displayName = "InputWithUnit";
