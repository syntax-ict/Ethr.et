import { cn } from "@/lib/utils";
import { tenantAddressAffixes } from "@/lib/tenant-address";

/**
 * The fixed text beside an organisation-slug input: `.ethr.et` after it in
 * subdomain mode, `ethr.et/` before it in single-host mode. Place one of each
 * side around the input; whichever does not apply renders nothing.
 *
 * `bordered` draws the divider used when the affix sits inside the input's own
 * border, on the side that faces the input.
 */
export function TenantAddressAffix({
  side,
  bordered = false,
}: {
  side: "prefix" | "suffix";
  bordered?: boolean;
}) {
  const text = tenantAddressAffixes()[side];
  if (!text) return null;

  return (
    <span
      className={cn(
        "shrink-0 text-sm text-muted-foreground",
        bordered && "px-3",
        bordered && (side === "prefix" ? "border-r" : "border-l"),
      )}
    >
      {text}
    </span>
  );
}
