"use client";

import * as React from "react";
import * as RadioGroupPrimitive from "@radix-ui/react-radio-group";
import { Circle } from "lucide-react";
import { cn } from "@/lib/utils";

/**
 * `default` is a column of round radios. `segmented` is the pill look of
 * `ui/tabs`: a few short options in one row, one of them selected. It has real
 * radio semantics, so arrow keys move the choice and a screen reader says
 * "radio, 2 of 3", where a row of buttons would say neither.
 */
type RadioGroupVariant = "default" | "segmented";
const VariantContext = React.createContext<RadioGroupVariant>("default");

const RadioGroup = React.forwardRef<
  React.ComponentRef<typeof RadioGroupPrimitive.Root>,
  React.ComponentPropsWithoutRef<typeof RadioGroupPrimitive.Root> & {
    variant?: RadioGroupVariant;
  }
>(({ className, variant = "default", ...props }, ref) => (
  <VariantContext.Provider value={variant}>
    <RadioGroupPrimitive.Root
      ref={ref}
      className={cn(
        variant === "segmented"
          ? "inline-flex min-h-10 max-w-full flex-wrap items-center justify-center gap-0 rounded-md bg-muted p-1 text-muted-foreground"
          : "grid gap-2",
        className,
      )}
      {...props}
    />
  </VariantContext.Provider>
));
RadioGroup.displayName = RadioGroupPrimitive.Root.displayName;

const RadioGroupItem = React.forwardRef<
  React.ComponentRef<typeof RadioGroupPrimitive.Item>,
  React.ComponentPropsWithoutRef<typeof RadioGroupPrimitive.Item>
>(({ className, children, ...props }, ref) => {
  const variant = React.useContext(VariantContext);

  if (variant === "segmented") {
    return (
      <RadioGroupPrimitive.Item
        ref={ref}
        className={cn(
          "inline-flex items-center justify-center whitespace-nowrap rounded-sm px-3 py-1.5 text-sm font-medium ring-offset-background transition-all focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 data-[state=checked]:bg-background data-[state=checked]:text-foreground data-[state=checked]:shadow-sm",
          className,
        )}
        {...props}
      >
        {children}
      </RadioGroupPrimitive.Item>
    );
  }

  return (
    <RadioGroupPrimitive.Item
      ref={ref}
      className={cn(
        "aspect-square h-4 w-4 rounded-full border border-primary text-primary shadow focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50",
        className,
      )}
      {...props}
    >
      <RadioGroupPrimitive.Indicator className="flex items-center justify-center">
        <Circle className="h-2.5 w-2.5 fill-current text-current" />
      </RadioGroupPrimitive.Indicator>
    </RadioGroupPrimitive.Item>
  );
});
RadioGroupItem.displayName = RadioGroupPrimitive.Item.displayName;

export { RadioGroup, RadioGroupItem };
